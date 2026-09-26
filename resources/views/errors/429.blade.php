@include('errors.layout', [
    'code' => 429,
    'title' => __('Too many requests'),
    'message' => __("You're moving a little fast. Please wait a moment, then try again."),
    'icon' => 'hand-raised',
    'retry' => true,
])
