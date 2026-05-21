@include('survey-core::survey.status', [
    'title' => '已完成填寫',
    'message' => $survey->uniqueness_message ?: '您已填寫過此問卷。',
])
