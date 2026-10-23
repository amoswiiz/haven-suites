<?php
namespace Tests\Feature;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    public function test_same_user_sees_only_each_company_database_and_roles(): void {
        $files = [];
        try {
            $user = User::create(['name'=>'Member','username'=>'member','email'=>'member@example.test','password'=>'Testing!Password123','active'=>true]);
            foreach (['alpha','beta'] as $slug) {
                $file = tempnam(storage_path('framework'),'tenant-test-'); $files[]=$file;
                $pdo = new \PDO('sqlite:'.$file); $pdo->exec('CREATE TABLE properties (id INTEGER PRIMARY KEY, name TEXT)');
                $pdo->prepare('INSERT INTO properties VALUES (1,?)')->execute([$slug.' private property']);
                $pdo = null;
                $tenant = Tenant::create(['name'=>$slug,'slug'=>$slug,'database_name'=>$file,'active'=>true]);
                $user->tenants()->attach($tenant,['roles'=>json_encode([$slug==='alpha'?'manager':'user']),'legacy_user_id'=>1]);
            }
            config(['database.connections.tenant'=>['driver'=>'sqlite','database'=>'','prefix'=>'','foreign_key_constraints'=>true]]);
            Route::get('/isolation/{tenant}',function () { return ['properties'=>db_connect()->table('properties')->get()->getResultArray(),'canManageReservations'=>auth()->user()->can('reservations.manage')]; })->middleware(['web','auth','tenant','tenant.member']);
            $this->actingAs($user)->get('/isolation/alpha')->assertOk()->assertJsonPath('properties.0.name','alpha private property')->assertJsonPath('canManageReservations',true);
            $this->get('/isolation/beta')->assertOk()->assertJsonPath('properties.0.name','beta private property')->assertJsonPath('canManageReservations',false);
            $this->get('/isolation/alpha')->assertOk()->assertJsonPath('properties.0.name','alpha private property');
            $this->assertFalse(app()->bound('currentTenant'));
        } finally {
            DB::purge('tenant'); foreach ($files as $file) if (is_file($file)) unlink($file);
        }
    }
}
