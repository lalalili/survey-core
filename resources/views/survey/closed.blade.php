@include('survey-core::survey.status', [
    'title' => '問卷已關閉',
    'message' => $survey->ends_at ? '此問卷已於 '.$survey->ends_at->format('Y-m-d H:i').' 結束。' : '此問卷目前不開放填寫。',
])
