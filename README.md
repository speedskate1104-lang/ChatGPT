# GOAT MUSIC: ロリポップ連携

GitHub Actions からロリポップに SSH 接続するための、手動の接続確認です。
対象は `goatmusic.jp` だけです。記事・設定・プラグイン・テーマの変更や定期実行は含みません。
AI/API の利用はありません。GitHub Actions の利用枠・料金は契約に依存します。

## 初回設定

このリポジトリの Settings → Secrets and variables → Actions → New repository secret に登録します。

| Secret | 内容 |
|---|---|
| `LOLIPOP_SSH_PASSWORD` | ロリポップの SSH パスワード |
| `LOLIPOP_KNOWN_HOSTS` | 接続先を検証済みの OpenSSH known_hosts エントリー |

秘密情報をチャット、コミット、ログに貼らないでください。

Mac のローカルターミナル（SSH 接続先ではない側）で、既存の公開ホスト鍵を取り出せます。

```bash
ssh-keygen -F '[ssh.lolipop.jp]:2222' -f "$HOME/.ssh/known_hosts"
```

`#` で始まらない鍵の行を `LOLIPOP_KNOWN_HOSTS` に登録します。ハッシュ化された行も対応します。
既存ファイルにあることだけでは接続先の真正性を証明しません。初回接続時の検証状況が不明な場合や警告がある場合は、
ロリポップの公式案内・サポート等の別経路でフィンガープリントを確認してください。
`ssh-keyscan` の結果を未検証のまま信頼したり、ホスト鍵検証を無効にしたりしません。

以前の `server gave bad signature for RSA key` は追加ホスト鍵の通知処理に関係する可能性がありますが、原因は未確定です。
このワークフローは登録済み鍵で接続先を厳密に検証し、追加鍵の自動登録は行いません。
接続先の鍵検証が失敗した場合は停止します。

## 接続確認

Actions → Lolipop connection check → Run workflow を実行します。
成功時は `SSH connection: OK` と PHP・WordPress のバージョンが表示されます。
これは SSH 接続とバージョン読み取りの検証であり、自動更新の設定完了を意味しません。

この方式はサーバー内で保存する認証情報や常駐プロセスを必要としません。
SSH の権限はサイト単位に制限されておらず、同一アカウントの他サイトにも届くため、
リポジトリの書き込み権限と Actions の実行権限は信頼できる担当者に限定してください。
Secrets 設定前にリポジトリの公開範囲とアクセス権を確認してください。

## 次の段階

接続確認後、指示された編集・更新を実行するワークフローを別途追加します。
現時点では本番サイトの編集、テーマ更新、cron 登録を一切自動化していません。
