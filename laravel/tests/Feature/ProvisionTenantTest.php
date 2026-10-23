<?php
namespace Tests\Feature;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class ProvisionTenantTest extends TestCase
{
    use RefreshDatabase;
    public function test_new_company_has_an_empty_isolated_operational_database(): void {
        $suffix=bin2hex(random_bytes(6)); $database='haven_test_'.$suffix; $slug='test-'.$suffix;
        $user=User::create(['name'=>'Provision test','username'=>'provision','email'=>'provision@example.test','password'=>'Testing!Password123','active'=>true]);
        $config=config('database.connections.tenant');
        $server=new \PDO('mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4',$config['username'],$config['password'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $statement=$server->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$statement->execute([$database]);
        $this->assertFalse($statement->fetch());
        try {
            $this->assertSame(0,Artisan::call('haven:tenant-create',['slug'=>$slug,'name'=>'Test company','email'=>$user->email,'--database'=>$database]));
            $tenant=Tenant::where('slug',$slug)->firstOrFail();
            config(['database.connections.tenant.database'=>$database]);DB::purge('tenant');
            $this->assertSame(0,DB::connection('tenant')->table('reservations')->count());
            $this->assertSame(0,DB::connection('tenant')->table('customers')->count());
            $this->assertSame(1,DB::connection('tenant')->table('users')->count());
            $this->assertSame(5,DB::connection('tenant')->table('expense_categories')->count());
            $this->actingAs($user)->get('/t/'.$slug.'/dashboard')->assertOk()->assertSee('Today');
        } finally {
            DB::purge('tenant');
            // Only the randomly named database created by this test is removed.
            $server->exec('DROP DATABASE IF EXISTS `'.$database.'`');
        }
    }
}
