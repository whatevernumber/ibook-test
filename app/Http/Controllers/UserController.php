<?php

namespace App\Http\Controllers;

use App\Helpers\AvatarGeneratorHelper;
use App\Helpers\ImageFromRequestHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Jobs\AvatarJob;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class UserController extends Controller
{

    public function index(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        return UserResource::collection(User::all());
    }

    public function get(User $user): UserResource
    {
        return new UserResource($user->load('finishedBooks')->loadCount(['favourites', 'onread', 'finishedbooks']));
    }

    /**
     * @throws AccessDeniedHttpException
     */
    public function create(CreateUserRequest $request, ImageFromRequestHelper $imageHelper): UserResource
    {
        if ($request->bearerToken() && Auth::guard('sanctum')->user()) {
            throw new AccessDeniedHttpException('Пользователь уже зарегистрирован', null, 403);
        }

        $data = $request->validated();

        $user = User::create($data);

        if ($request->file('avatar')) {
            $avatar = $imageHelper->saveAvatar($request->file('avatar'), env('PROFILE_IMAGE_PATH'));
            $user->image = $avatar;
        } else {
            AvatarJob::dispatch($user);
        }

        $user->save();
        return new UserResource($user);
    }

    /**
     * @throws AccessDeniedHttpException
     */
    public function update(UpdateUserRequest $request, User $user, ImageFromRequestHelper $imageHelper): UserResource
    {
        if ($request->user()->cannot('update', $user)) {
           throw new AccessDeniedHttpException('Вы не можете редактировать другого пользователя', null, 403);
        }

        $data = $request->validated();
        $user->fill($data);
        $deleteAvatar = $data['delete_avatar'] ?? 0;

        if ($request->file('avatar')) {
            $imageHelper->delete($user->image, 'public', env('PROFILE_IMAGE_PATH'));
            $avatar = $imageHelper->saveAvatar($request->file('avatar'), env('PROFILE_IMAGE_PATH'));
            $user->image = $avatar;
        } elseif ($user->image && $deleteAvatar) {
            $imageHelper->delete($user->image, 'public', env('PROFILE_IMAGE_PATH'));
            $user->image = null;
            AvatarJob::dispatch($user);
        } elseif (!$user->image) {
            AvatarJob::dispatch($user);
        }

        $user->save();

        return new UserResource($user);
    }

    /**
     * @throws AccessDeniedHttpException
     * @throws \Exception
     */
    public function delete(Request $request, User $user, ImageFromRequestHelper $imageHelper): Response
    {
        if ($request->user()->cannot('delete', $user)) {
            throw new AccessDeniedHttpException('Вы не можете редактировать другого пользователя', null, 403);
        }

        DB::beginTransaction();

        try {
            if ($user->image) {
                $imageHelper->delete($user->image, 'public', env('PROFILE_IMAGE_PATH'));
            }

            $user->finishedBooks()->detach();
            $user->onRead()->detach();
            $user->favourites()->detach();
            $request->user()->tokens()->delete();
            $user->delete();
        } catch(\Exception $e) {
            DB::rollBack();
            throw new \Exception($e->getMessage());
        }

        DB::commit();
        return response('', 204);
    }
}
