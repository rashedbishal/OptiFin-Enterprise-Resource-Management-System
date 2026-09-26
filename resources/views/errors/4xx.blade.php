{{-- Fallback for any 4xx status without its own page (e.g. 405, 410, 422). --}}
@include('errors.layout', [
    'code' => $exception->getStatusCode(),
    'title' => __('Request could not be completed'),
    'message' => __('Something about this request wasn\'t quite right. Go back and try again.'),
    'icon' => 'question-mark-circle',
])
