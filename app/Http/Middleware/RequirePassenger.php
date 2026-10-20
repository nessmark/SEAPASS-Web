<?php
namespace App\Http\Middleware;
use App\Models\Passenger;
use Closure;
use Illuminate\Http\Request;
class RequirePassenger {
 public function handle(Request $request, Closure $next) {
  abort_unless($request->user() instanceof Passenger, 403, 'A passenger account is required.');
  return $next($request);
 }
}
