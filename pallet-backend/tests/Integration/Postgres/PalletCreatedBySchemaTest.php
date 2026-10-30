<?php

namespace Tests\Integration\Postgres;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgres')]
class PalletCreatedBySchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('La extensión pdo_pgsql no está instalada en este runtime.');
        }

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Este gate sólo corre contra PostgreSQL.');
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_created_by_tiene_fk_null_on_delete_e_indice_en_postgres(): void
    {
        $foreignKey = collect(Schema::getForeignKeys('pallets'))->first(
            fn (array $foreign) => $foreign['columns'] === ['created_by']
        );

        $this->assertNotNull($foreignKey);
        $this->assertSame('users', $foreignKey['foreign_table']);
        $this->assertStringContainsStringIgnoringCase('set null', (string) $foreignKey['on_delete']);

        $this->assertTrue(
            collect(Schema::getIndexes('pallets'))->contains(
                fn (array $index) => $index['name'] === 'pallets_created_by_index'
                    && $index['columns'] === ['created_by']
            ),
        );

        $userId = User::factory()->create()->id;
        $palletId = DB::table('pallets')->insertGetId([
            'code' => 'PAL-FK-PG',
            'status' => 'open',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->where('id', $userId)->delete();

        $this->assertNull(DB::table('pallets')->where('id', $palletId)->value('created_by'));
    }

    public function test_activity_logs_tiene_un_solo_indice_que_empieza_por_order_id(): void
    {
        $leading = collect(Schema::getIndexes('activity_logs'))
            ->filter(fn (array $index) => ($index['columns'][0] ?? null) === 'order_id')
            ->values();

        $this->assertCount(1, $leading);
    }
}
