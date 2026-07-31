    /*
     * 顯示條件與跳題規則的求值。
     *
     * 這個 partial 只含純 JavaScript，不得加入任何 Blade 語法（連 Blade 註解都不行）：
     * tests/js/conditionConsistency.test.js 會直接把檔案內容當成 JS 求值，拿與 PHP
     * 端相同的 fixture 跟權威實作 ConditionGroupEvaluator 做一致性比對。測試裡有一道
     * 守衛會擋下 Blade 指令。
     *
     * 由 scripts.blade.php 在其 IIFE 內以 Blade include 引入，因此可直接用同作用域的
     * getAnswerValue()；其餘呼叫點（evaluateBranching、resolveNextPageKey 等）沿用
     * conditionGroupPasses 這個名字，不受抽出影響。
     *
     * 注意：連註解裡都不能出現 Blade 指令記號，Blade 不會因為它在 JS 註解中就略過。
     */
    function valueMatches(current, expected) {
        if (Array.isArray(current)) { return current.includes(expected); }
        return current === expected;
    }

    function isUnanswered(value) {
        return value === null || value === '' || (Array.isArray(value) && value.length === 0);
    }

    function conditionPasses(condition) {
        var current = getAnswerValue(condition.field_key || '');
        var expected = condition.value;
        var op = condition.op || 'equals';

        if (op === 'is_empty') { return isUnanswered(current); }
        if (op === 'is_not_empty') { return !isUnanswered(current); }

        // 目標題目未作答時，除了 is_empty / is_not_empty 之外一律不成立，
        // 與後端 ConditionGroupEvaluator 保持一致。少了這道守衛，
        // not_equals / not_contains 會在未作答時成立，而 less_than 會因為
        // Number(null) === 0 而誤判「評分小於 N」成立。
        if (isUnanswered(current)) { return false; }

        if (op === 'not_equals') { return !valueMatches(current, expected); }
        if (op === 'contains') { return valueMatches(current, expected) || String(current || '').includes(String(expected || '')); }
        if (op === 'not_contains') { return !(valueMatches(current, expected) || String(current || '').includes(String(expected || ''))); }
        if (op === 'greater_than') { return Number(current) > Number(expected); }
        if (op === 'less_than') { return Number(current) < Number(expected); }
        if (op === 'between') {
            var min = Array.isArray(expected) ? expected[0] : expected?.min;
            var max = Array.isArray(expected) ? expected[1] : expected?.max;
            return Number(current) >= Number(min) && Number(current) <= Number(max);
        }
        return valueMatches(current, expected);
    }

    function conditionGroupPasses(group) {
        var conditions = Array.isArray(group.conditions) ? group.conditions : [];
        if (conditions.length === 0) { return true; }
        // Each entry is a leaf condition or a nested group (recurse on the latter).
        var evaluate = function (node) {
            return (node && Array.isArray(node.conditions)) ? conditionGroupPasses(node) : conditionPasses(node);
        };
        if ((group.logic || 'and') === 'or') {
            return conditions.some(evaluate);
        }
        return conditions.every(evaluate);
    }
