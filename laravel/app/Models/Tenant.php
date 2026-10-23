<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Tenant extends Model
{
    protected $fillable = ['name','slug','database_name','active'];
    protected function casts(): array { return ['active'=>'boolean']; }
    public function users() { return $this->belongsToMany(User::class)->withPivot('roles','legacy_user_id'); }
}
