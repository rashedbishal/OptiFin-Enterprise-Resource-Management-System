@include('errors.layout', [
    'code' => 401,
    'title' => __('Sign in required'),
    'message' => __('You need to be signed in to view this page. Please sign in and try again.'),
    'icon' => 'lock-closed',
])
