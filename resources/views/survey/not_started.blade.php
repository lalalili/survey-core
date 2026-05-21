@include('survey-core::survey.status', [
    'title' => '問卷尚未開放',
    'message' => $survey->starts_at ? '此問卷將於 '.$survey->starts_at->format('Y-m-d H:i').' 開放填寫。' : '此問卷尚未開放填寫。',
])
