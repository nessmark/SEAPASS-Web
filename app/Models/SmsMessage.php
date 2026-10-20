<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SmsMessage extends Model {
 protected $fillable = ['deduplication_key','to','body','status','error_message','provider_id'];
 protected $hidden = ['body'];
 public function getMaskedToAttribute(): string { return substr($this->to, 0, 4).'••••'.substr($this->to, -3); }
}
