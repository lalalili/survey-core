# Changelog

All notable changes to `lalalili/survey-core` will be documented in this file.

## [1.4.0] - 2026-08-01

### Fixed

公開填答頁的顯示條件求值與伺服器端 `ConditionGroupEvaluator` 不一致，導致部分
條件在受訪者端靜默失效（不會有任何錯誤訊息）。全部改為對齊權威實作：

- **條件值為數字時不再永遠不成立。** 先前用嚴格相等 `current === expected` 比對，
  但送出的答案永遠是字串，條件值卻常在 schema 裡存成數字（評分、線性刻度、NPS），
  於是這類顯示條件從未成立、被控制的題目永遠不顯示。這是本次影響最大的一項。
- **支援 `greater_than_or_equal` / `less_than_or_equal`（含 `>=`、`<=`）。**
  先前這些運算子會掉進最後的 `valueMatches` 而被當成 `equals` 比較。
- **`contains` 不再把陣列 join 後當字串比對。** 先前 `['a','b']` 會誤中 `'a,b'`。
- **`is_empty` 改為先 trim。** 純空白字串先前被當成已作答。
- 條件群組遞迴加上與後端相同的深度上限，避免畸形資料造成無限遞迴。

### Changed

- 顯示條件求值抽成 `survey/partials/condition-evaluator` partial，由
  `scripts.blade.php` 以 Blade include 引入。純 JavaScript、無 Blade 語法，
  維持公開填答頁零 build step 與 CDN 模式相容。

### Added

- `tests/Fixtures/condition-consistency.json`：跨實作共用 fixture，同時餵給
  `tests/Feature/ConditionConsistencyTest.php`（PHP，斷言權威行為）與
  `tests/js/conditionConsistency.test.js`（公開頁，把 partial 當純 JS 求值）。
  兩邊日後再度分歧會被這組測試擋下。
- CI 新增 JS tests job。

## [1.1.1] - 2026-07-28

### Changed

- 將範例問卷測試 fixture 的驗證去識別化，保留中性內容與結構契約。

## [1.1.0] - 2026-07-28

### Added

- 單行與多行文字題新增最少中文字數驗證，使用 Unicode Han 字元計數。

## [1.0.1] - 2026-07-27

### Fixed

- `php` 約束由 `^8.2` 更正為 `^8.4`。相依鏈上的
  `spatie/laravel-activitylog ^5.0` 硬性要求 php `^8.4`,原本的宣告與
  現實不符,在 8.2/8.3 上根本無法安裝。
- `phpstan.neon.dist` 的 `tmpDir` 由 `../../storage/...` 改為套件內的
  `build/phpstan`,不再假設套件位於宿主 `packages/` 底下。

### Added

- 掛上 `lalalili/.github` 的共用 CI 與 Release workflow。此套件先前因為
  相依私有 repo 而無法在 CI 解析依賴,長期沒有自動化測試。

## [1.0.0] - 2026-07-27

### Changed

- 首個穩定版。此後遵循
  [SEMVER.md](https://github.com/lalalili/.github/blob/main/SEMVER.md)
  定義的 public API 契約,宿主可安全使用 `^1.0` 約束。
- 對其他 lalalili 套件的約束一律收斂為 `^1.0`,取代先前 `^0.x`
  與多段 OR 的寫法。
- `repositories` 改用 GitHub VCS,不再依賴宿主 `packages/` 底下的
  兄弟目錄;測試資源改從 `vendor/lalalili/*` 讀取。
- 移除 `minimum-stability` / `prefer-stable` 宣告,授權統一為 MIT。

### 為什麼是 1.0.0

Composer 對 `^0.1.1` 的解讀是 `>=0.1.1 <0.2.0`,0.x 期間每發一個 minor
都需要所有宿主手動改 `composer.json`,否則 `composer update` 永遠拿不到
新版。本套件生態曾因此讓宿主停在數十個 commit 之前而無人察覺。

## v0.2.0 - 2026-07-05

### Added
- Google Drive 檔案上傳整合：`GoogleDriveClientFactory`（OAuth、token 刷新、資料夾）、
  問卷綁定帳號與資料夾、送出後非同步上傳、匯出輸出 Drive 連結；含檔案上傳題的問卷未綁定時阻擋發佈
- 重複核選題（`selection_based`）：來源題選項彙整、草稿還原補回已勾選、後台分析依來源題分布
- 選項與題組隨機排序：選項分組、組內／組順序隨機、題組與題組內題目隨機
- 下拉題依選項組渲染 `optgroup` 分組
- 從 CSV 匯入題目的 Action
- 巢狀選擇題 XLSX 匯入

### Changed
- 結構型題型不可個性化時清除隱藏與個性化鍵
- 統一總計題填寫提示、問卷選項設定與標題題型

### Fixed
- 公開頁 XSS 淨化、CascadeSelect 驗證與選項容量查詢效能
- 統一問卷填寫進度與巢狀選擇檢核、Builder 存取設定
- 避免重複建立 media 資料表；防止匯出寫檔失敗時誤標完成
- 使用條款勾選框改為只在最後一頁顯示；移除感謝頁綠色打勾圖示

## v0.1.1

- 問卷引擎初版：token 機制、個性化欄位、多頁問卷、跳題邏輯、collector 追蹤、CSV 匯出
