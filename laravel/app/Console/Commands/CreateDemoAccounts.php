<?php
namespace App\Console\Commands;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class CreateDemoAccounts extends Command
{
    protected $signature='haven:demo-accounts {--tenant=haven}';
    protected $description='Create one local test login per role, with generated passwords.';
    public function handle(): int {
        if (!app()->environment('local','testing')) throw new \RuntimeException('Demo accounts are only allowed in local/testing environments.');
        $tenant=Tenant::where('slug',$this->option('tenant'))->firstOrFail();
        config(['database.connections.tenant.database'=>$tenant->database_name]);DB::purge('tenant');
        $rows=[];
        foreach (array_keys(config('haven.roles')) as $role) {
            $email='demo.'.$role.'@haven-suites.test';
            if (User::where('email',$email)->exists()) { $this->warn('Existing account left unchanged: '.$email); continue; }
            $password='Hs!'.Str::random(17);
            DB::beginTransaction();DB::connection('tenant')->beginTransaction();
            try {
                $user=User::create(['name'=>'Demo '.ucfirst($role),'username'=>'demo_'.$role,'email'=>$email,'password'=>$password,'active'=>true]);
                $legacyId=DB::connection('tenant')->table('users')->insertGetId(['username'=>'demo_'.$role,'active'=>1,'created_at'=>now(),'updated_at'=>now()]);
                $user->tenants()->attach($tenant,['roles'=>json_encode([$role]),'legacy_user_id'=>$legacyId]);
                DB::connection('tenant')->table('staff')->insert(['user_id'=>$legacyId,'full_name'=>'Demo '.ucfirst($role),'phone'=>'0700000000','email'=>$email,'role'=>$role,'location'=>'Nairobi','status'=>'active','notes'=>'Local role testing account','created_at'=>now(),'updated_at'=>now()]);
                DB::connection('tenant')->commit();DB::commit();
                $rows[]=[$role,$email,$password];
            } catch (\Throwable $e) { DB::rollBack();DB::connection('tenant')->rollBack();throw $e; }
        }
        $this->table(['Role','Email','Password'],$rows);
        return self::SUCCESS;
    }
}
