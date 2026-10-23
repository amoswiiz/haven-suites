<?php
namespace Database\Factories;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
class UserFactory extends Factory
{
    protected $model = \App\Models\User::class;
    public function definition(): array {
        return ['name'=>fake()->name(),'username'=>fake()->unique()->userName(),'email'=>fake()->unique()->safeEmail(),
            'email_verified_at'=>now(),'password'=>Hash::make(Str::random(32)),'active'=>true,'remember_token'=>Str::random(10)];
    }
}
