# Using models in migrations in Laravel application

Migrations in simple cases just change your database structure, add tables and columns, and you don't have to change the data you already have stored in your database. But there are cases, when it is also necessary to change data when running database migrations.

When you are manipulating data within migrations, then it is recommended to never use models in migrations because models evolve in time and it breaks your migration. It is suggested that you should better use raw SQL queries or raw methods of ORM (Object Relational Mapper) what you are using.

But, ORMs have been built for a reason to simplify working with data and there may be a solution that we could still depend on models within migrations.

So, before we jump into a solution, let's have a case where we can reproduce the issue in a Laravel application, we're trying to resolve.

## Reproducible use case

Set up a new Laravel project.

```
laravel new using-models-in-migrations
```

Configure database credentials.

```
php artisan migrate
```

We see that everything worked for now.

Let's now create a migration that forces `name` column to be unique in users table.

```
php artisan make:migration make_name_unique_in_users
```

```
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unique('name');
        });
    }
```

Seems quite straightforward and everything still works when you run the migrations again (`php artisan migrate:fresh`). However, let's expect that we already have users in the database at this point with the same names.

```
    public function up()
    {
        factory(User::class, 3)->create([
            'name' => 'Joe',
        ]);

        factory(User::class, 2)->create([
            'name' => 'Jane',
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->unique('name');
        });
    }
```

Run `php artisan migrate:fresh` and see that, we're getting now an error `SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'Joe' for key 'users.users_name_unique'`. So let's fix that by making users' names unique before changing the table structure.

```
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
```

Everything seems to works again (`php artisan migrate:fresh`). Let's now make a change in our User model and add [soft deleting functionality](https://laravel.com/docs/7.x/eloquent#soft-deleting).

Add `SoftDeletes` trait to User model and import it (`use Illuminate\Database\Eloquent\SoftDeletes;`).

Create a migration to add the soft delete column.

```
php artisan make:migration add_soft_deletes_to_users
```

```
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }
```

Run `php artisan migrate:fresh` and we're getting the following error:

```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'users.deleted_at'
```

BOOM, we finally ended up with the issue why it's discouraged to ever use models in migrations - a mistake that we did to manipulate data in `make_name_unique_in_users` migration.

## Raw ORM methods

We could switch out the usage of models with raw ORM methods like in the following example:

```
    public function up()
    {
        factory(User::class, 3)->create([
            'name' => 'Joe',
        ]);

        factory(User::class, 2)->create([
            'name' => 'Jane',
        ]);

        $users = DB::table('users')->select('name')->groupBy('name')->get();

        foreach ($users as $groupedUser) {
            $usersWithSameName = DB::table('users')->where('name', $groupedUser->name)->get();

            foreach ($usersWithSameName as $key => $userWithSameName) {
                $userWithSameName->name .= ' ('.$key.')';
                DB::table('users')->updateOrInsert([
                    'id' => $userWithSameName->id,
                ], (array) $userWithSameName);
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('name');
        });
    }
```

It basically works, does the job and the migrations run successfully once again (`php artisan migrate:fresh`). Although, there are downsides of using raw ORM methods instead of models - the helper methods that you're used to, are not available like `save()` and relationship methods, no mass assignment protection, etc.

We could depend on raw ORM methods in migrations, but we'd have to be more careful as developers in these situation. However we may have a solution how to still use models in migrations.

## Model snapshots

Let's take a second and think why we ended up with that model issue in the first place. The problem was, that within `make_name_unique_in_users` migration we depended on a model User. But instead of depending on the latest version of User model (which is evolving in time), we actually wanted to use the version of User model that existing in the exact time when the migration was created.

So, if we take that into account - could our migration make a snapshot of a model and use that, not the latest one? We might.

Let's copy-paste our User model (`User.php`) which we had at the point when we created `make_name_unique_in_users` migration to somewhere we could use that. I, for example, created a directory `database/migrations/Migration_2020_04_03_055738_make_name_unique_in_users` and pasted `User.php` in there - acts as a snapshot of the model.

Laravel doesn't recognise the classes in that location by default, why we need to tweak `composer.json` and change the autoloader section.

```
        "psr-4": {
            "App\\": "app/",
            "Migrations\\": "database/migrations/"
        },
```

And create a new autoloader file, so the change would have an effect.

```
composer dump-autoload
```

And tweak the snaphot User model to have a new correct namespace.

```
<?php

namespace Migrations\Migration_2020_04_03_055738_make_name_unique_in_users;
```

And switch out the User model that is used in `make_name_unique_in_users` from `App\User` to `Migrations\Migration_2020_04_03_055738_make_name_unique_in_users\User`.

If you now run `php artisan migrate:fresh` you'd get `Unable to locate factory for [Migrations\Migration_2020_04_03_055738_make_name_unique_in_users\User].` error. That happens in our case, because our migration depends on User facotry as well and this in turn depends on the User model. And as we want to depend on the snaphot version of the model, we'd have to create a model factory for that version of User model. We can declare it within our migration.

```
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
```

Make sure, you also add the imports needed when you just copy-paste the factory from `database/factories/UserFactory.php`. And you also need to import `Factory` class.

```
use Faker\Generator as Faker;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Factory;
```

We can now check that everything works, run `php artisan migrate:fresh`.

```
Dropped all tables successfully.
Migration table created successfully.
Migrating: 2014_10_12_000000_create_users_table
Migrated:  2014_10_12_000000_create_users_table (0.01 seconds)
Migrating: 2019_08_19_000000_create_failed_jobs_table
Migrated:  2019_08_19_000000_create_failed_jobs_table (0.01 seconds)
Migrating: 2020_04_03_055738_make_name_unique_in_users
Migrated:  2020_04_03_055738_make_name_unique_in_users (0.03 seconds)
Migrating: 2020_04_03_061254_add_soft_deletes_to_users
Migrated:  2020_04_03_061254_add_soft_deletes_to_users (0 seconds)
```

## Conclusion

The raw ORM method solution may look more elegant and preferred by many of you. It's shorter and no files have to be duplicated. However there may be times, when you really want to have those helper model methods available. And this is one way to make it happen. Just keep in mind, models can break your migrations.