<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    /**
     * User can register
     */
    public function test_user_can_register(): void
    {
        $user = User::factory()->make();
        $user->password_confirmation = $user->password;

       $response = $this->postJson('/api/register', [
            'name' => $user->name,
            'email' => $user->email,
            'password' => $user->password,
            'password_confirmation' => $user->password_confirmation,
        ]);

       $response->assertCreated();

        $this->assertDatabaseHas('users',
            [
                'name' => $user->name,
                'email' => $user->email
            ]
        );
    }

    /**
     * User can log in
     */
    public function test_user_can_login(): void
    {
        $user = User::factory()->state(['password' => 'test'])->create();

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'test',
        ]);

        $response->assertJsonStructure(
            [
                'data' => [
                    'token'
                ]
            ]
        );
    }

    /**
     * User can change its credentials
     */
    public function test_user_can_update_self(): void
    {
        $user = User::factory()->create();
        $updatedUser = User::factory()->state(['password' => null])->make();

        Sanctum::actingAs($user);

        $this->patchJson('/api/users/' . $user->id, [
            'name' => $updatedUser->name,
            'email' => $updatedUser->email,
        ]);

        $this->assertDatabaseHas('users',
            [
                'id' => $user->id,
                'name' => $updatedUser->name,
                'email' => $updatedUser->email,
            ]
        );
    }

    /**
     * User can't update another user
     */
    public function test_user_cannot_update_another_user(): void
    {
        $user = User::factory()->create();
        $anotherUser = User::factory()->create();

        $updatedUser = User::factory()->state(['password' => null])->make();

        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/users/' . $anotherUser->id, [
            'name' => $updatedUser->name,
            'email' => $updatedUser->email,
        ]);

        $response->assertStatus(403);

        $this->assertDatabaseHas('users',
            [
                'id' => $anotherUser->id,
                'name' => $anotherUser->name,
                'email' => $anotherUser->email,
            ]
        );
    }

    /**
     * Admin can update another user
     */
    public function test_admin_cannot_update_another_user(): void
    {
        $user = User::factory()->admin()->create();
        $anotherUser = User::factory()->create();

        $updatedUser = User::factory()->state(['password' => null])->make();

        Sanctum::actingAs($user);

        $this->patchJson('/api/users/' . $anotherUser->id, [
            'name' => $updatedUser->name,
            'email' => $updatedUser->email,
        ]);

        $this->assertDatabaseHas('users',
            [
                'id' => $anotherUser->id,
                'name' => $updatedUser->name,
                'email' => $updatedUser->email,
            ]);
    }

    /**
     * User can delete their account
     */
    public function test_user_can_be_deleted(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->delete('/api/users/' . $user->id);

        $response->assertStatus(204);

        $this->assertDatabaseMissing('users',
        [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ]);
    }

    /**
     * User can't delete another user's account
     */
    public function test_user_cannot_be_deleted_by_another_user(): void
    {
        $user = User::factory()->create();
        $anotherUser = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->delete('/api/users/' . $anotherUser->id);

        $response->assertStatus(403);

        $this->assertDatabaseHas('users',
            [
                'id' => $anotherUser->id,
                'name' => $anotherUser->name,
                'email' => $anotherUser->email,
            ]);
    }

    /**
     * Admin can delete their account
     */
    public function test_admin_can_delete_another_user_account(): void
    {
        $user = User::factory()->admin()->create();
        $anotherUser = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->delete('/api/users/' . $anotherUser->id);

        $response->assertStatus(204);

        $this->assertDatabaseMissing('users',
            [
                'id' => $anotherUser->id,
                'name' => $anotherUser->name,
                'email' => $anotherUser->email,
            ]);
    }

    /**
     * User's bonus is calculated correctly
     */
    public function test_bonus_calculates_correct(): void
    {
        $user = User::factory()->make();
        $bookPrice = fake()->randomNumber(2, true);
        $user->saveBonus($bookPrice);

        $expectedBonus = floor($bookPrice / 100 * User::BONUS_PERCENT);

        $this->assertEquals($expectedBonus, $user->bonus);
    }
}
