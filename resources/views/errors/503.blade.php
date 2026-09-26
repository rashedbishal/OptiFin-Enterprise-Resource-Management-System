@include('errors.layout', [
    'code' => 503,
    'title' => __('Down for maintenance'),
    'message' => __("We're making some improvements and will be back shortly. Thanks for your patience."),
    'icon' => 'wrench-screwdriver',
    'retry' => true,
])
