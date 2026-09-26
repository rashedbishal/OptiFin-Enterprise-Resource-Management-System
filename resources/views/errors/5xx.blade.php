{{-- Fallback for any 5xx status without its own page (e.g. 502, 504). --}}
@include('errors.layout', [
    'code' => $exception->getStatusCode(),
    'title' => __('Service unavailable'),
    'message' => __("We're having trouble on our side right now. Please try again in a moment."),
    'icon' => 'exclamation-triangle',
    'retry' => true,
])
