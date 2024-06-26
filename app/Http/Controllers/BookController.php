<?php

namespace App\Http\Controllers;

use App\Helpers\ImageUploadHelper;
use App\Http\Controllers\Controller;
use App\Helpers\IsbnHelper;
use App\Http\Requests\BookRequest;
use App\Http\Resources\BookResource;
use App\Jobs\GoogleBookCoverJob;
use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BookController extends Controller
{
    //

    public function index(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        return BookResource::collection(Book::paginate(env('BOOKS_PER_PAGE')));
     }

    /**
     * @throws NotFoundHttpException
     */
    public function get(string $isbn): BookResource
    {
        if (str_ends_with($isbn, 'X')) {
            $isbn = str_replace('X', '', $isbn);
        }

        if (!$book = Book::find($isbn)) {
            throw new NotFoundHttpException('Такой книги не существует', null, 404);
        }

        return new BookResource($book);
    }

    /**
     * @throws ValidationException
     * @throws ConflictHttpException
     * @throws Exception
     */
    public function create(BookRequest $request, IsbnHelper $isbnHelper): BookResource
    {
        $data = $request->validated();

        // removes everything except numbers
        $data['isbn'] = preg_replace('/([ISBN:-]|\s)/', '', $data['isbn']);

        // validates the isbn checksum
        $isValid = $isbnHelper->checkIsbn($data['isbn']);

        if (!$isValid) {
            throw ValidationException::withMessages(['isbn' => 'Некорретный isbn']);
        }

        // if the number contains 'x', it will be removed and the flag in the db will be set
        if (str_ends_with($data['isbn'], 'X')) {
            $data['isbn'] = str_replace('X', '', $data['isbn']);
            $data['has_x'] = true;
        }

        // checks if the book with this isbn exists
        $exists = Book::find($data['isbn']);

        if ($exists) {
            throw new ConflictHttpException('Книга с таким ISBN уже есть в системе', null, 409);
        }

        // Wrapped in a transaction to avoid a book being saved without its author/genre
        DB::beginTransaction();

        try {
            $book = Book::create($data);

            // processes book's authors
            foreach ($data['authors'] as $new_author) {
                $author = Author::firstOrCreate([
                    'name' => $new_author,
                ]);

                $book->authors()->save($author);
            }

            // processes book's genres
            foreach ($data['genres'] as $selected_genre) {
                $genre = Genre::firstOrCreate([
                    'value' => $selected_genre,
                ]);

                $book->genres()->save($genre);
            }
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception($e->getMessage());
        }

        DB::commit();

        $book->refresh();

        GoogleBookCoverJob::dispatch($book);

        return new BookResource($book);
    }

    /**
     * @throws NotFoundHttpException
     * @throws ValidationException
     * @throws Exception
     */
    public function update(BookRequest $request, string $isbn, IsbnHelper $isbnHelper, ImageUploadHelper $imageHelper): BookResource
    {
        $isbnChanged = false;

        // finds the book to change
        if (str_ends_with($isbn, 'X')) {
            $isbn = str_replace('X', '', $isbn);
        }

        if (!$book = Book::find($isbn)) {
            throw new NotFoundHttpException('Такой книги не существует', null, 404);
        }

        $data = $request->validated();

        // removes everything except numbers
        $data['isbn'] = preg_replace('/([ISBN:-]|\s)/', '', $data['isbn']);

        // if the isbn was changed, check if the new one is correct and free
        if (intval($data['isbn']) !== $book->isbn) {
            $isbnChanged = true;
            // validates the isbn checksum
            $isValid = $isbnHelper->checkIsbn($data['isbn']);

            if (!$isValid) {
                throw ValidationException::withMessages(['isbn' => 'Некорретный isbn']);
            }

            // detaches all the relations
            $book->authors()->detach();
            $book->genres()->detach();
            $book->savedBy()->detach();
            $book->addedBy()->detach();
            $book->boughtBy()->detach();

            if ($book->image) {
                $imageHelper->delete($book->image->image, 'public', env('BOOK_COVER_PATH'));
                $book->image()->delete();
            }
        }

        // Wrapped in a transaction to avoid a book being saved without its author/genre
        DB::beginTransaction();

        try {
            $book->fill($data);
            $book->save();

            // takes existing relationships and transform it to array to check if there were changes added
            $authors = $book->authors()->pluck('name')->toArray();
            $genres = $book->genres->pluck('value')->toArray();
            Arr::flatten($authors);
            Arr::flatten($genres);

            // if there are new authors added
            if ($diff = array_diff($data['authors'], $authors)) {
                foreach ($diff as $author) {
                    $author = Author::firstOrCreate([
                        'name' => $author,
                    ]);

                    $book->authors()->save($author);
                }
            }

            // if some of old authors were erased
            if ($diff = array_diff($authors, $data['authors'])) {
                foreach ($diff as $author) {
                    $author = Author::where(['name' => $author])->first();
                    $book->authors()->detach($author->id);
                }
            }

            // if there are new genres added
            if ($diff = array_diff($data['genres'], $genres)) {
                foreach ($diff as $genre) {
                    $genre = Genre::firstOrCreate([
                        'value' => $genre,
                    ]);

                    $book->genres()->save($genre);
                }
            }

            // if some of old genres were erased
            if ($diff = array_diff($genres, $data['genres'])) {
                foreach ($diff as $genre) {
                    $genre = Genre::where(['value' => $genre])->first();
                    $book->genres()->detach($genre->id);
                }
            }

            // simpler but always detaches all the genres and adds it again
//                $book->genres()->detach();
//
//                foreach ($data['genres'] as $selectedGenre) {
//                    $genre = Genre::firstOrCreate([
//                        'value' => $selectedGenre,
//                    ]);
//
//                    $book->genres()->save($genre);
//                }

        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception($e->getMessage());
        }

        DB::commit();

        $book->refresh();

        // if isbn changed, the cover must be replaced
        if ($isbnChanged) {
            GoogleBookCoverJob::dispatch($book);
        }

        return new BookResource($book);
    }

    /**
     * @throws NotFoundHttpException
     * @throws Exception
     */
    public function delete(string $isbn, ImageUploadHelper $imageHelper): Response
    {
        // finds the book to change
        if (str_ends_with($isbn, 'X')) {
            $isbn = str_replace('X', '', $isbn);
        }

        if (!$book = Book::find($isbn)) {
            throw new NotFoundHttpException('Такой книги не существует', null, 404);
        }

        DB::beginTransaction();

        try {
            $book->authors()->detach();
            $book->genres()->detach();
            $book->savedBy()->detach();
            $book->addedBy()->detach();
            $book->boughtBy()->detach();

            if ($book->image()->get()->isNotEmpty()) {
                $imageHelper->delete($book->image->image, 'public', env('BOOK_COVER_PATH'));
                $book->image()->delete();
            }

            $book->delete();
        } catch(Exception $e) {
            DB::rollBack();
            throw new Exception($e->getMessage());
        }

        DB::commit();

        return response('', 204);
    }

    /**
     * @throws BadRequestHttpException
     * @throws NotFoundHttpException
     */
    public function find(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $genre = $request->query('genre');
        $author = $request->query('author');
        $text = $request->query('text');

        // if there are no parameters in the query
        if (!$genre && !$author && !$text) {
            throw new BadRequestHttpException('Укажите критерии поиска', null, 400);
        }

        $books = Book::with('authors', 'genres');

        if ($text) {
            $books = $books->where('title', 'ILIKE', '%' . $text . '%');

            // if there is text in the query and no authors mentioned, try to search in the authors name as well
            if (!$author) {
                $books = $books->orWhereHas('authors', function ($q) use ($text) {
                    $q->where('name', 'ILIKE', '%' . $text . '%');
                });
            }
        }

        if ($genre) {
            $books = $books->whereHas('genres', function ($q) use ($genre) {
                $q->where('value', $genre);
            });
        }

        if ($author) {
            $books = $books->whereHas('authors', function ($q) use ($author) {
                $q->where('name', 'ILIKE', '%' . $author . '%');
            });
        }

        $books = $books->paginate(env('BOOKS_PER_PAGE'));

        if ($books->isEmpty()) {
            throw new NotFoundHttpException('Нет подходящих результатов', null, 404);
        }

        return BookResource::collection($books);
    }
}
