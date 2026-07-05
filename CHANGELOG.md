# Changelog

All notable changes to `lalalili/survey-core` will be documented in this file.

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
