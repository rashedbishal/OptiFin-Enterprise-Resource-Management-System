@include('errors.layout', [
    'code' => 403,
    'title' => __('Access Denied'),
    'message' => __("You don't have permission to do that. If you think this is a mistake, reach out to your account admin."),
    'icon' => 'shield-exclamation',
])
