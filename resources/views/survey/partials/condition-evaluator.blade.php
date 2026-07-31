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
    /* 以下四個判斷刻意對齊 ConditionGroupEvaluator，一致性由 tests/Fixtures/
     * condition-consistency.json 這份共用 fixture 把關（PHP 與 JS 兩側各跑一次）。 */

    /* 對應 PHP 的 (string) 轉型比對。送出的答案永遠是字串，條件值卻可能在 schema 裡
     * 存成數字（評分、線性刻度、NPS 都常見）；用嚴格相等會讓那些條件永遠不成立。 */
    function valueMatches(current, expected) {
        if (Array.isArray(current)) { return current.map(String).includes(String(expected)); }
        return String(current ?? '') === String(expected ?? '');
    }

    /* 陣列只做成員比對，不把陣列 join 後當字串找子字串——後者會讓 ['a','b'] 誤中 'a,b'。 */
    function valueContains(current, expected) {
        if (Array.isArray(current)) { return current.map(String).includes(String(expected)); }
        return String(current ?? '').includes(String(expected ?? ''));
    }

    /* 對應 PHP 的 blank()：null／空字串／純空白／空陣列視為未作答；0 與 '0' 不是。 */
    function isUnanswered(value) {
        if (value === null || value === undefined) { return true; }
        if (typeof value === 'string') { return value.trim() === ''; }
        if (Array.isArray(value)) { return value.length === 0; }
        return false;
    }

    /* 對應 PHP 的 is_numeric()：空字串與 null 都不是數值。 */
    function isNumericValue(value) {
        if (typeof value === 'number') { return Number.isFinite(value); }
        if (typeof value === 'string') { return value.trim() !== '' && Number.isFinite(Number(value)); }
        return false;
    }

    function valueBetween(current, expected) {
        if (!isNumericValue(current) || expected === null || typeof expected !== 'object') { return false; }
        var min = Array.isArray(expected) ? expected[0] : expected.min;
        var max = Array.isArray(expected) ? expected[1] : expected.max;
        if (!isNumericValue(min) || !isNumericValue(max)) { return false; }
        return Number(current) >= Number(min) && Number(current) <= Number(max);
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

        var bothNumeric = isNumericValue(current) && isNumericValue(expected);

        if (op === 'not_equals') { return !valueMatches(current, expected); }
        if (op === 'contains') { return valueContains(current, expected); }
        if (op === 'not_contains') { return !valueContains(current, expected); }
        if (op === 'greater_than' || op === '>') { return bothNumeric && Number(current) > Number(expected); }
        if (op === 'greater_than_or_equal' || op === '>=') { return bothNumeric && Number(current) >= Number(expected); }
        if (op === 'less_than' || op === '<') { return bothNumeric && Number(current) < Number(expected); }
        if (op === 'less_than_or_equal' || op === '<=') { return bothNumeric && Number(current) <= Number(expected); }
        if (op === 'between') { return valueBetween(current, expected); }
        return valueMatches(current, expected);
    }

    /* depth 與 PHP 的 ConditionGroupEvaluator::MAX_DEPTH 相同，用來擋畸形輸入造成的無限遞迴。 */
    function conditionGroupPasses(group, depth) {
        var currentDepth = depth || 0;
        if (currentDepth > 10) { return true; }

        var conditions = (group && Array.isArray(group.conditions) ? group.conditions : [])
            .filter(function (node) { return node !== null && typeof node === 'object'; });
        if (conditions.length === 0) { return true; }

        // Each entry is a leaf condition or a nested group (recurse on the latter).
        var evaluate = function (node) {
            return Array.isArray(node.conditions)
                ? conditionGroupPasses(node, currentDepth + 1)
                : conditionPasses(node);
        };
        if (String(group.logic || 'and').toLowerCase() === 'or') {
            return conditions.some(evaluate);
        }
        return conditions.every(evaluate);
    }
