@include('survey-core::survey.status', [
    'title' => '問卷已額滿',
    'message' => $survey->quota_message ?: '此問卷回收數量已達上限。',
])
