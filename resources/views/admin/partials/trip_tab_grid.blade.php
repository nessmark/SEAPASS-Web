@forelse($trips as $schedule)
    @include('admin.partials.trip_card', ['schedule' => $schedule, 'variant' => $variant])
@empty
@endforelse
<div class="trip-empty" id="{{ $variant }}-empty" @if($trips->isNotEmpty()) style="display: none;" @endif>{{ $emptyMessage }}</div>
