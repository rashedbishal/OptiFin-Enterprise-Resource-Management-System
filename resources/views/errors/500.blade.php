@include('errors.layout', [
    'code' => 500,
    'title' => __('Something went wrong'),
    'message' => __('We hit an unexpected problem on our side. Please try again in a moment.'),
    'icon' => 'exclamation-triangle',
    'retry' => true,
])
