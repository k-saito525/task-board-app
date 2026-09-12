# Step 0: リポジトリの初期整備

- **日付**: 2026-09-12
- **状態**: ✅ 完了

## ゴール

`api/` + `web/` のモノレポとして開発を始められる土台を作る。

## やったこと

### 1. 環境確認

```bash
php -v                   # PHP 8.4.10
node -v                  # v24.3.0
docker --version         # 29.7.2
docker compose version   # v5.4.0
```

Laravel 13 は **PHP 8.3 以上**が必須。手元は 8.4.10 なので条件を満たしている。

### 2. composer の更新

```bash
composer --version   # 2.4.4 (2022-10-27)
composer self-update
composer --version   # 2.10.3 (2026-08-27)
```

4年近く前のバージョンだったため、`composer create-project` に入る前に更新した。古いままだと新しい `composer.json` の記法に対応していない可能性がある。

### 3. ドキュメント構成の決定

| ファイル | 役割 |
|---|---|
| `docs/DESIGN.md` | 設計・技術選定・DBスキーマ・API設計・スコープ |
| `docs/PROGRESS.md` | ステップ一覧と状態。詰まった点の横断索引 |
| `docs/worklog/` | ステップごとの作業記録（**このディレクトリ**） |

進捗表と作業記録を分けたのは、**一覧性と詳細を両立させる**ため。PROGRESS.md だけだと詰まった内容が書けず、worklog だけだと全体像が見えない。

### 4. `.gitignore` の作成

モノレポなので `api/`（Laravel）と `web/`（Next.js）の両方を対象に含めた。1点だけ意図的な例外がある。

```gitignore
# web/src/lib/api/schema.d.ts は OpenAPI からの生成物だが、
# 契約のずれを差分でレビューできるよう、あえてコミットする
```

生成物は通常コミットしないが、**API の変更がフロントの型にどう波及したかを PR の差分で確認したい**ため例外とした。CI でもこの差分を使って契約ずれを検出する（Step 13）。

## 詰まった点

このステップでは特になし。

## 判断メモ

- **生成物をコミットするかは目的次第。** 「生成物はコミットしない」は絶対のルールではなく、差分レビューの価値が上回るなら commit する

## 成果物

```
task-board-app/
├── .gitignore          (新規)
└── docs/
    ├── DESIGN.md       (新規)
    ├── PROGRESS.md     (新規)
    └── worklog/        (新規)
```
