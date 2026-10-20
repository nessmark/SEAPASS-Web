<?php
namespace App\Http\Middleware;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
class RequireRole {
 public function handle(Request $request, Closure $next, string ...$roles) {
  $user = $request->user();
  abort_unless($user instanceof User && $user->is_active !== false && in_array(strtolower(str_replace([' ', '-'], '_', (string) $user->role)), $roles, true), 403, 'This account does not have access to this action.');
  return $next($request);
 }
}
