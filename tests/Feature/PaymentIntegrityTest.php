<?php
namespace Tests\Feature;
use App\Models\{Boat,Booking,Fare,Passenger,TripSchedule,User};
use App\Services\{BookingService,PayMongoService,SmsService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http,Mail,URL};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
class PaymentIntegrityTest extends TestCase {
 use RefreshDatabase;
 private Passenger $passenger;
 private TripSchedule $trip;
 protected function setUp():void {
  parent::setUp(); Mail::fake(); Http::preventStrayRequests();
  config(['services.paymongo.mock'=>true,'services.paymongo.webhook_secret'=>'sign-secret','services.textlink.enabled'=>false]);
  $boat=Boat::create(['name'=>'Ferry','passenger_capacity'=>5,'status'=>'Active']);
  $this->trip=TripSchedule::create(['boat_id'=>$boat->id,'route'=>'Surigao → San Jose(Dinagat)','departure_time'=>now()->addDay(),'status'=>'Scheduled','available_seats'=>5,'seat_map'=>app(BookingService::class)->initializeSeatMap(5)]);
  Fare::create(['route'=>$this->trip->route,'regular'=>250,'student'=>200,'senior'=>200]);
  $this->passenger=Passenger::create(['name'=>'Passenger','email'=>'passenger@example.com','phone'=>'09123456789','password'=>bcrypt('password')]);
 }
 private function payload(array $extra=[]):array { return array_replace(['schedule_id'=>$this->trip->id,'seat_count'=>1,'seat_numbers'=>['1A'],'amount_collected'=>1],$extra); }
 private function checkout(array $extra=[]):Booking {
  Sanctum::actingAs($this->passenger);
  $response=$this->postJson('/api/payments/checkout',$this->payload($extra))->assertOk();
  return Booking::findOrFail($response->json('booking.id'));
 }
 private function webhook(Booking $b,array $attributes=[],?string $session=null,int $age=0) {
  $payment=array_replace(['amount'=>25000,'currency'=>'PHP','status'=>'paid'],$attributes);
  $payload=['data'=>['id'=>'evt_1','attributes'=>['type'=>'checkout_session.payment.paid','data'=>['id'=>$session??$b->paymongo_checkout_session_id,'attributes'=>['metadata'=>['booking_id'=>$b->id],'payments'=>[['id'=>'pay_real_1','attributes'=>$payment]]]]]]];
  $body=json_encode($payload);$t=time()-$age;$sig=hash_hmac('sha256',$t.'.'.$body,'sign-secret');
  return $this->call('POST','/api/paymongo/webhook',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','HTTP_PAYMONGO_SIGNATURE'=>"t=$t,te=$sig"],$body);
 }
 public function test_checkout_requires_passenger_and_ignores_client_fare():void {
  $this->postJson('/api/payments/checkout',$this->payload())->assertUnauthorized();
  $b=$this->checkout(); $this->assertEquals(250,(float)$b->amount_collected); $this->assertTrue($b->reservation_expires_at->isFuture());
 }
 public function test_passenger_cannot_checkout_someone_elses_booking():void {
  $b=$this->checkout(); $other=Passenger::create(['name'=>'Other','email'=>'other@example.com','password'=>bcrypt('password')]);Sanctum::actingAs($other);
  $this->postJson('/api/payments/checkout',['booking_id'=>$b->id])->assertForbidden();
 }
 public function test_provider_failure_returns_owned_hold_for_retry_without_reserving_again():void {
  Sanctum::actingAs($this->passenger);
  config(['services.paymongo.mock'=>false,'services.paymongo.secret_key'=>'sk_test_fake']);
  Http::fake(['*'=>Http::response([],503)]);
  $failure=$this->postJson('/api/payments/checkout',$this->payload())->assertStatus(503);
  $id=$failure->json('booking.id');
  $this->assertNotNull($id);
  $this->assertNotNull($failure->json('booking.reservation_expires_at'));
  $this->assertEquals(4,$this->trip->fresh()->available_seats);
  $this->postJson('/api/payments/checkout',['booking_id'=>$id])->assertStatus(503)->assertJsonPath('booking.id',$id);
  $this->assertEquals(1,Booking::count());
  config(['services.paymongo.mock'=>true]);
  $this->postJson('/api/payments/checkout',['booking_id'=>$id])->assertOk()->assertJsonPath('booking.id',$id);
  $this->assertEquals(4,$this->trip->fresh()->available_seats);
  $this->assertEquals(1,Booking::count());
 }
 public function test_staff_cannot_impersonate_passenger_by_matching_id():void {
  Sanctum::actingAs(User::factory()->create(['role'=>'admin','is_active'=>true]));
  $this->getJson('/api/bookings')->assertForbidden();
 }
 public function test_checkout_requires_exact_seat_count_and_future_active_trip():void {
  Sanctum::actingAs($this->passenger);
  $this->postJson('/api/payments/checkout',$this->payload(['seat_count'=>2]))->assertStatus(409);
  $this->trip->update(['status'=>'Cancelled']);
  $this->postJson('/api/payments/checkout',$this->payload())->assertStatus(409);
 }
 public function test_browser_success_never_confirms_and_requires_signed_url():void {
  $b=$this->checkout();
  $this->get('/payment/success?ref='.$b->reference_number.'&status=paid')->assertForbidden();
  $this->get(URL::temporarySignedRoute('payment.success',now()->addHour(),['ref'=>$b->reference_number]))->assertOk();
  $this->assertEquals('pending',$b->fresh()->status);
 }
 public function test_unsigned_missing_secret_and_old_webhooks_are_rejected():void {
  $b=$this->checkout();
  $this->postJson('/api/paymongo/webhook',[])->assertStatus(400);
  $this->webhook($b,[],null,360)->assertStatus(400);
  config(['services.paymongo.webhook_secret'=>null]);$this->webhook($b)->assertStatus(400);
  $this->assertNull($b->fresh()->payment_received_at);
 }
 public function test_amount_currency_and_payment_state_must_match():void {
  $b=$this->checkout();
  $this->webhook($b,['amount'=>100])->assertUnprocessable();
  $this->webhook($b,['currency'=>'USD'])->assertUnprocessable();
  $this->webhook($b,['status'=>'failed'])->assertUnprocessable();
  $this->webhook($b,[],'cs_unrelated')->assertOk();
  $this->assertEquals('pending',$b->fresh()->status);
 }
 public function test_duplicate_paid_events_do_not_duplicate_confirmation_or_resurrect_cancelled():void {
  $b=$this->checkout();$this->webhook($b)->assertOk();$this->webhook($b)->assertOk();
  $this->assertEquals('confirmed',$b->fresh()->status);
  Mail::assertQueued(\App\Mail\BookingConfirmationMail::class,1);
  app(BookingService::class)->cancelBooking($b);$this->webhook($b)->assertOk();
  $this->assertEquals('cancelled',$b->fresh()->status);
 }
 public function test_expired_holds_release_seats_and_late_payment_requires_refund_review():void {
  $b=$this->checkout();$b->update(['reservation_expires_at'=>now()->subMinute()]);
  $this->artisan('bookings:expire')->assertSuccessful();
  $this->assertEquals(5,$this->trip->fresh()->available_seats);$this->assertEquals('expired',$b->fresh()->status);
  $this->webhook($b)->assertOk();$b->refresh();$this->assertEquals('expired',$b->status);$this->assertTrue($b->payment_review_required);$this->assertNull($b->qr_code);
 }
 public function test_provider_failure_does_not_fall_back_to_mock_and_refund_failure_keeps_booking():void {
  $b=$this->checkout();$b->update(['paymongo_checkout_session_id'=>null,'paymongo_payment_id'=>'pay_real_paid','status'=>'to_be_confirmed']);
  config(['services.paymongo.mock'=>false,'services.paymongo.secret_key'=>'sk_test_fake']);Http::fake(['*'=>Http::response([],500)]);
  $admin=User::factory()->create(['role'=>'admin','is_active'=>true]);
  $this->actingAs($admin)->postJson('/admin/bookings/'.$b->id.'/reject-refund',['reason'=>'Invalid ID'])->assertStatus(502);
  $this->assertEquals('to_be_confirmed',$b->fresh()->status);$this->assertNull($b->fresh()->paymongo_refund_id);
  $this->expectException(\RuntimeException::class);app(PayMongoService::class)->createCheckoutSession($b);
 }
 public function test_mock_payment_endpoints_disabled_when_explicit_mock_is_off():void {
  $b=$this->checkout();config(['services.paymongo.mock'=>false]);
  $this->get('/payment/mock-checkout?ref='.$b->reference_number.'&session_id='.$b->paymongo_checkout_session_id)->assertNotFound();
  $this->post('/payment/mock-checkout/pay',['ref'=>$b->reference_number,'session_id'=>$b->paymongo_checkout_session_id])->assertNotFound();
 }
 public function test_admin_routes_reject_scanner_and_disabled_admin():void {
  $user=User::factory()->create(['role'=>'scanner_staff','is_active'=>true]);
  $this->actingAs($user)->getJson('/admin/dashboard')->assertForbidden();
  $user->update(['role'=>'admin','is_active'=>false]);$this->actingAs($user)->getJson('/admin/dashboard')->assertForbidden();
 }
 public function test_profile_update_and_password_require_current_password():void {
  Sanctum::actingAs($this->passenger);
  $this->patchJson('/api/passenger/profile',['name'=>'Updated','phone'=>'09179998888'])->assertOk();
  $this->putJson('/api/passenger/password',['current_password'=>'wrong','password'=>'newpassword','password_confirmation'=>'newpassword'])->assertUnprocessable();
  $this->putJson('/api/passenger/password',['current_password'=>'password','password'=>'newpassword','password_confirmation'=>'newpassword'])->assertOk();
 }
 public function test_textlink_honors_body_failure_and_queued_no_duplicate_sends():void {
  config(['services.textlink.enabled'=>true,'services.textlink.api_key'=>'fake-key']);
  Http::fake(['*'=>Http::sequence()->push(['ok'=>false])->push(['ok'=>true,'queued'=>true])]);
  $sms=app(SmsService::class);
  $this->assertEquals('failed',$sms->send('09123456789','Message','one')->status);
  $this->assertEquals('queued',$sms->send('09123456789','Message','two')->status);
  $sms->send('09123456789','Message','two');Http::assertSentCount(2);
  Http::assertSent(fn($request)=>$request['phone_number']==='+639123456789');
 }
 public function test_manifest_excludes_unpaid_and_prevents_csv_formula_cells():void {
  $b=$this->checkout();$b->update(['passenger_name'=>'=EVIL()']);$admin=User::factory()->create(['role'=>'admin','is_active'=>true]);
  $this->actingAs($admin)->getJson('/admin/trip-schedules/'.$this->trip->id.'/manifest?format=json')->assertJsonPath('passenger_count',0);
  $b->update(['status'=>'confirmed']);
  $this->actingAs($admin)->getJson('/admin/trip-schedules/'.$this->trip->id.'/manifest?format=json')->assertJsonPath('passenger_count',1);
  $this->assertStringContainsString("'=EVIL()",$this->get('/admin/trip-schedules/'.$this->trip->id.'/manifest')->streamedContent());
 }
 public function test_signed_boarding_is_per_seat_and_replays_are_rejected():void {
  $this->travelTo(\Carbon\Carbon::parse('2026-11-01 07:00','Asia/Manila'));
  $this->trip->update(['departure_time'=>now()->addHour()]);
  $b=$this->checkout(['seat_count'=>2,'seat_numbers'=>['1A','1B']]);
  $b->update(['status'=>'confirmed']);$b->generateQrCode();
  $tickets=$b->passenger_tickets;
  Sanctum::actingAs(User::factory()->create(['role'=>'scanner_staff','is_active'=>true]));
  $this->postJson('/api/bookings/verify-ticket',['reference_number'=>$b->reference_number])->assertUnprocessable()->assertJsonPath('status','INVALID_SIGNATURE');
  $this->postJson('/api/bookings/verify-ticket',['reference_number'=>$tickets[0]['qr_payload']])->assertOk();
  $this->assertFalse($b->fresh()->is_boarded);
  $this->postJson('/api/bookings/verify-ticket',['reference_number'=>$tickets[0]['qr_payload']])->assertStatus(409);
  $this->postJson('/api/bookings/verify-ticket',['reference_number'=>$tickets[1]['qr_payload']])->assertOk();
  $this->assertTrue($b->fresh()->is_boarded);$this->assertCount(2,$b->fresh()->boarded_seats);
 }
 public function test_draft_and_archived_advisories_are_hidden_and_sms_is_not_falsely_sent():void {
  $admin=User::factory()->create(['role'=>'admin','is_active'=>true]);
  $this->actingAs($admin)->postJson('/admin/travel-advisory',['title'=>'Draft only','message'=>'Draft message','status'=>'Draft'])->assertOk()->assertJsonPath('push_status','Not Sent');
  $this->getJson('/api/advisories')->assertJsonCount(0,'data');
  $this->actingAs($admin)->postJson('/admin/travel-advisory',['title'=>'Port update','message'=>'Cancelled sailing','status'=>'Published','publish_push'=>true])->assertOk()->assertJsonPath('push_status','Queued');
  $travel=\App\Models\TravelAdvisory::where('title','Port update')->firstOrFail();
  $this->getJson('/api/advisories')->assertJsonCount(1,'data');
  $this->actingAs($admin)->patchJson('/admin/travel-advisory/'.$travel->id.'/archive')->assertOk();
  $this->getJson('/api/advisories')->assertJsonCount(0,'data');
  $this->assertDatabaseHas('sms_messages',['status'=>'not_configured']);
 }

 public function test_pending_refund_webhook_and_sync_command_reconcile_status():void {
   $b=$this->checkout();
   $b->update([
       'paymongo_checkout_session_id' => null,
       'paymongo_payment_id' => 'pay_real_1',
       'paymongo_refund_id' => 'ref_123',
       'refund_amount' => 250,
       'refund_status' => 'pending',
       'status' => 'cancelled',
   ]);

   // Test 1: Provider refund webhook callback
   $payload = [
       'data' => [
           'id' => 'evt_ref_1',
           'attributes' => [
               'type' => 'refund.succeeded',
               'data' => [
                   'id' => 'ref_123',
                   'type' => 'refund',
                   'attributes' => [
                       'amount' => 25000,
                       'status' => 'succeeded',
                       'payment_id' => 'pay_real_1',
                   ],
               ],
           ],
       ],
   ];
   $body = json_encode($payload);
   $t = time();
   $sig = hash_hmac('sha256', $t.'.'.$body, 'sign-secret');
   $this->call('POST', '/api/paymongo/webhook', [], [], [], [
       'CONTENT_TYPE' => 'application/json',
       'HTTP_ACCEPT' => 'application/json',
       'HTTP_PAYMONGO_SIGNATURE' => "t=$t,te=$sig",
   ], $body)->assertOk();

   $this->assertEquals('refunded', $b->fresh()->refund_status);
   $this->assertFalse($b->fresh()->payment_review_required);

   // Test 2: Scheduled reconciliation command payments:sync-refunds
   $b2 = $this->checkout(['seat_numbers' => ['1B']]);
   $b2->update([
       'paymongo_checkout_session_id' => null,
       'paymongo_payment_id' => 'pay_real_2',
       'paymongo_refund_id' => 'ref_456',
       'refund_amount' => 250,
       'refund_status' => 'processing',
       'status' => 'cancelled',
   ]);
   config(['services.paymongo.mock' => false, 'services.paymongo.secret_key' => 'sk_test_fake']);
   Http::fake([
       'https://api.paymongo.com/v1/refunds/ref_456' => Http::response([
           'data' => [
               'id' => 'ref_456',
               'type' => 'refund',
               'attributes' => [
                   'amount' => 25000,
                   'status' => 'succeeded',
                   'payment_id' => 'pay_real_2',
               ],
           ],
       ], 200),
   ]);
   $this->artisan('payments:sync-refunds')->assertSuccessful();
   $this->assertEquals('refunded', $b2->fresh()->refund_status);
  }
}
