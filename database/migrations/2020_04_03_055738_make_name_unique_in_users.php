<?php

use Migrations\Migration_2020_04_03_055738_make_name_unique_in_users\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Factory;
use Faker\Generator as Faker;
use Illuminate\Support\Str;

class MakeNameUniqueInUsers extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        app(Factory::class)->define(User::class, function (Faker $faker) {
            return [
                'name' => $faker->name,
                'email' => $faker->unique()->safeEmail,
                'email_verified_at' => now(),
                'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
                'remember_token' => Str::random(10),
            ];
        });
    
        factory(User::class, 3)->create([
            'name' => 'Joe',
        ]);

        factory(User::class, 2)->create([
            'name' => 'Jane',
        ]);

        $users = User::select('name')->groupBy('name')->get();

        foreach ($users as $groupedUser) {
            $usersWithSameName = User::where('name', $groupedUser->name)->get();

            foreach ($usersWithSameName as $key => $userWithSameName) {
                $userWithSameName->name .= ' ('.$key.')';
                $userWithSameName->save();
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('name');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            //
        });
    }
}
