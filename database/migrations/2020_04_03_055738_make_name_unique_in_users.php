<?php

use App\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class MakeNameUniqueInUsers extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
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
