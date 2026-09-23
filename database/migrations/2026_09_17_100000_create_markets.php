<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table): void {
            if (! Schema::hasColumn('countries', 'iso2')) {
                $table->char('iso2', 2)->nullable()->unique('uq_countries_iso2');
            }
        });

        Schema::create('markets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->char('code', 2);
            $table->string('web_host', 190)->nullable();
            $table->string('api_host', 190)->nullable();
            $table->char('currency_code', 3);
            $table->string('timezone', 64);
            $table->string('phone_country_code', 8);
            $table->string('default_locale', 16);
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique('country_id', 'uq_markets_country');
            $table->unique('code', 'uq_markets_code');
            $table->unique('web_host', 'uq_markets_web_host');
            $table->unique('api_host', 'uq_markets_api_host');
            $table->unique(['id', 'country_id'], 'uq_markets_id_country');
            $table->unique(['id', 'currency_code'], 'uq_markets_id_currency');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE markets ADD CONSTRAINT chk_markets_active_hosts CHECK (is_active = 0 OR (web_host IS NOT NULL AND api_host IS NOT NULL))');
        }

        if (DB::connection()->pretending()) {
            return;
        }

        $jordanId = $this->country('JO', ['jordan', 'JO'], 'الأردن');
        $egyptId = $this->country('EG', ['egypt', 'EG'], 'مصر');
        $uaeId = $this->country('AE', ['uae', 'AE'], 'الإمارات العربية المتحدة');
        $rootDomain = (string) config('markets.root_domain');
        $now = now();

        DB::table('markets')->insert([
            [
                'country_id' => $jordanId, 'code' => 'JO',
                'web_host' => 'jo.'.$rootDomain, 'api_host' => 'api-jo.'.$rootDomain,
                'currency_code' => 'JOD', 'timezone' => 'Asia/Amman',
                'phone_country_code' => '+962', 'default_locale' => 'ar-JO',
                'features' => '{}', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'country_id' => $egyptId, 'code' => 'EG',
                'web_host' => 'eg.'.$rootDomain, 'api_host' => 'api-eg.'.$rootDomain,
                'currency_code' => 'EGP', 'timezone' => 'Africa/Cairo',
                'phone_country_code' => '+20', 'default_locale' => 'ar-EG',
                'features' => '{}', 'is_active' => false, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'country_id' => $uaeId, 'code' => 'AE',
                'web_host' => null, 'api_host' => null,
                'currency_code' => 'AED', 'timezone' => 'Asia/Dubai',
                'phone_country_code' => '+971', 'default_locale' => 'ar-AE',
                'features' => '{}', 'is_active' => false, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('markets');

        Schema::table('countries', function (Blueprint $table): void {
            if (Schema::hasColumn('countries', 'iso2')) {
                $table->dropColumn('iso2');
            }
        });
    }

    /** @param array<int, string> $legacyCodes */
    private function country(string $iso2, array $legacyCodes, string $name): int
    {
        $country = DB::table('countries')->whereIn('code', $legacyCodes)->orWhere('iso2', $iso2)->first();

        if ($country === null) {
            $id = DB::table('countries')->insertGetId([
                'name' => $name,
                'code' => $iso2,
                'iso2' => $iso2,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $id = (int) $country->id;
            DB::table('countries')->where('id', $id)->update(['iso2' => $iso2]);
        }

        return $id;
    }
};
