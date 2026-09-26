@include('errors.layout', [
    'code' => 419,
    'title' => __('Page expired'),
    'message' => __('Your session expired for security reasons. Refresh the page and try again.'),
    'icon' => 'clock',
    'retry' => true,
])
