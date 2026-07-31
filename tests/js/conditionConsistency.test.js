import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import fixture from '../Fixtures/condition-consistency.json';

/**
 * 顯示條件求值的一致性測試（公開填答頁側）。
 *
 * 與 `tests/Feature/ConditionConsistencyTest.php` 共用同一份 fixture。`expected` 是
 * PHP 權威實作 ConditionGroupEvaluator 的結果；這裡檢查受訪者實際執行的那份
 * JavaScript 是否跟得上。跟不上的案例在 fixture 裡以 `client_divergence` 記錄目前
 * 的實際回傳值——那些是已知落差，pin 住以免無聲擴大。
 */

const EVALUATOR_PATH = new URL(
  '../../resources/views/survey/partials/condition-evaluator.blade.php',
  import.meta.url,
);

const source = readFileSync(EVALUATOR_PATH, 'utf-8');

/**
 * 把 partial 當成純 JS 求值，注入測試用的 getAnswerValue。
 * 公開頁是把同一份原始碼 inline 進 scripts.blade.php 的 IIFE，這裡重現同樣的作用域。
 */
function createEvaluator(answers) {
  const factory = new Function(
    'getAnswerValue',
    `${source}\nreturn { conditionPasses: conditionPasses, conditionGroupPasses: conditionGroupPasses };`,
  );

  return factory((fieldKey) => (Object.prototype.hasOwnProperty.call(answers, fieldKey)
    ? answers[fieldKey]
    : null));
}

/** 公開頁的 getAnswerValue 從 DOM 取值，永遠是字串或字串陣列或 null。 */
const answers = Object.fromEntries(
  Object.entries(fixture.answers).map(([fieldKey, answer]) => [
    fieldKey,
    answer.value === null
      ? null
      : Array.isArray(answer.value)
        ? answer.value.map(String)
        : String(answer.value),
  ]),
);

function evaluate(group) {
  return createEvaluator(answers).conditionGroupPasses(group);
}

describe('condition-evaluator partial', () => {
  it('contains no Blade syntax, so it stays valid standalone JavaScript', () => {
    // 這是本測試把檔案當 JS 求值的前提，也是公開頁能保持零 build 的前提。
    // Blade 不會因為指令出現在 JS 註解裡就略過它——實測寫了「@ include」當說明文字
    // 就會讓整個 view 編譯失敗，所以這裡擋下任何 @ 開頭的識別字，不只帶括號的。
    expect(source).not.toMatch(/(^|[^@\w])@[a-zA-Z]/);
    expect(source).not.toContain('{{');
    expect(source).not.toContain('{!!');
  });

  it('is the single source the public page includes', () => {
    const scripts = readFileSync(
      new URL('../../resources/views/survey/partials/scripts.blade.php', import.meta.url),
      'utf-8',
    );

    expect(scripts).toContain("@include('survey-core::survey.partials.condition-evaluator')");
    // 抽出後不該還有第二份定義留在原檔。
    expect(scripts).not.toContain('function conditionPasses(');
    expect(scripts).not.toContain('function conditionGroupPasses(');
  });
});

describe('顯示條件求值：公開填答頁 vs PHP 權威實作', () => {
  const agreeing = fixture.cases.filter((testCase) => !testCase.client_divergence);
  const diverging = fixture.cases.filter((testCase) => testCase.client_divergence);

  it.each(agreeing.map((testCase) => [testCase.name, testCase]))(
    '與 PHP 一致：%s',
    (_name, testCase) => {
      expect(evaluate(testCase.group)).toBe(testCase.expected);
    },
  );

  it.each(diverging.map((testCase) => [testCase.name, testCase]))(
    '已知落差：%s',
    (_name, testCase) => {
      const divergence = testCase.client_divergence;

      expect(
        divergence.actual,
        `「${testCase.name}」已不再是落差，請從 fixture 移除 client_divergence`,
      ).not.toBe(testCase.expected);

      expect(evaluate(testCase.group), divergence.why).toBe(divergence.actual);
    },
  );
});
