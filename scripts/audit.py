"""Read-only WordPress audit. Unknown evidence never authorizes an update."""
import concurrent.futures
import datetime
import html
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request


def fetch(url, data=None):
    encoded = None if data is None else urllib.parse.urlencode(data).encode()
    req = urllib.request.Request(url, data=encoded, headers={"User-Agent": "GOATMusicReadOnlyAudit/1.0"})
    with urllib.request.urlopen(req, timeout=30) as response:
        return json.load(response)


def version_compare(a, b):
    # Fail closed on prerelease/unknown formats rather than guessing PHP ordering.
    if not re.fullmatch(r"\d+(?:\.\d+)*", str(a)) or not re.fullmatch(r"\d+(?:\.\d+)*", str(b)):
        raise ValueError("unrecognized version format")
    x, y = [int(v) for v in a.split('.')], [int(v) for v in b.split('.')]
    length = max(len(x), len(y))
    x += [0] * (length - len(x))
    y += [0] * (length - len(y))
    return (x > y) - (x < y)


def affected(version, operators):
    seen = False
    result = True
    for side in ('min', 'max'):
        limit = operators.get(side + '_version')
        op = operators.get(side + '_operator')
        if limit is None and op is None:
            continue
        if not limit or not op:
            raise ValueError('incomplete vulnerability range')
        comparison = version_compare(version, limit)
        predicates = {'lt': comparison < 0, 'le': comparison <= 0, 'lte': comparison <= 0,
                      'gt': comparison > 0, 'ge': comparison >= 0, 'gte': comparison >= 0,
                      'eq': comparison == 0, 'ne': comparison != 0}
        if op not in predicates:
            raise ValueError('unsupported range operator')
        seen = True
        result = result and predicates[op]
    if not seen:
        raise ValueError('no usable vulnerability range')
    return result


def vulnerability_records(content, kind):
    if 'vulnerability' not in content:
        raise ValueError('missing vulnerability field')
    if kind in ('plugin', 'theme') and not content.get('name'):
        raise ValueError('component not identified in vulnerability database')
    records = content['vulnerability']
    if records is None:
        return []
    if not isinstance(records, list):
        raise ValueError('unexpected vulnerability list format')
    return records


def text(value):
    return html.unescape(str(value)).replace('\n', ' ').replace('\r', ' ').replace('|', '/') .replace('<', '&lt;').replace('>', '&gt;')


def main(path):
    inv = json.load(open(path))
    if inv['home'].rstrip('/') != 'https://goatmusic.jp' or inv['siteurl'].rstrip('/') != 'https://goatmusic.jp':
        raise ValueError('unexpected site identity; stopped')
    report = ['# goatmusic.jp セキュリティ点検', '', '取得日時: ' + inv['timestamp'],
              '', '**読み取りのみ。本番の更新・設定変更は実行していません。**',
              '互換性・復元手段が未検証のため、自動で更新可能な対象はありません。',
              '', '## インストール済み構成と更新候補', '',
              '| 種別 | 名前 | 使用中 | 現在 | 更新候補 | 自動更新設定 |',
              '|---|---|---|---|---|---|']
    problems, unknown, matches = [], [], []
    official = {}
    for kind in ('plugins', 'themes'):
        try:
            payload = {kind: inv[kind]}
            if kind == 'plugins':
                payload['active'] = inv['active_plugins']
            official[kind] = fetch('https://api.wordpress.org/' + kind + '/update-check/1.1/',
                                   {kind: json.dumps(payload), 'all': 'true', 'version': inv['core'], 'php': inv['php_cli']})
            if not isinstance(official[kind], dict) or 'error' in official[kind]:
                raise ValueError('unexpected official API response')
        except Exception as exc:
            official[kind] = {}
            unknown.append(f'{kind} 更新照合: {type(exc).__name__}')
    for kind in ('plugins', 'themes'):
        updates = official[kind].get('plugins' if kind == 'plugins' else 'themes', {})
        no_update = official[kind].get('no_update', {})
        for slug, item in inv[kind].items():
            candidate = updates.get(slug, {}).get('new_version')
            status = candidate or ('候補なし' if slug in no_update else '照合不明')
            if status == '照合不明':
                unknown.append(kind + '/' + slug + ': 公式更新情報なし（独自配布・子テーマ等の可能性）')
            auto = str(item.get('auto_update', '未調査'))
            report.append('| ' + ' | '.join(map(text, [kind, slug, item['active'], item['Version'], status, auto])) + ' |')
    try:
        core = fetch('https://api.wordpress.org/core/version-check/1.7/?' + urllib.parse.urlencode({'version': inv['core'], 'php': inv['php_cli']}))
        offers = core.get('offers', [])
        latest = offers[0]['current']
        report += ['', f'WordPress: {text(inv["core"])} / 公式提示版: {text(latest)}', f'PHP CLI: {text(inv["php_cli"])}（Web実行版は別途確認）']
    except Exception as exc:
        unknown.append('WordPress 公式更新照合: ' + type(exc).__name__)
    flags = inv['flags']
    if not flags.get('DISALLOW_FILE_EDIT'):
        problems.append('管理画面のファイル編集を明示的に禁止する設定なし。実際の利用可否は権限・プラグインにも依存。')
    if flags.get('WP_DEBUG'):
        problems.append('WP_DEBUG が有効。本番でのログ・画面への情報露出を点検。')
    if not inv['salts_appear_set']:
        problems.append('認証用キー・salt が不足または既定値の可能性。値は出力していません。')
    if not flags.get('FORCE_SSL_ADMIN'):
        problems.append('FORCE_SSL_ADMIN の明示設定なし。HTTPSサイトなので即座に平文ログイン可能と断定しない。')
    if str(inv['users_can_register']) == '1':
        problems.append('ユーザーの新規登録が有効。必要性と既定権限を確認。')
    if inv['administrator_count'] > 1:
        problems.append(f'管理者 {inv["administrator_count"]} 件。各アカウントの必要性を確認（名前は出力しません）。')
    now = datetime.datetime.now(datetime.timezone.utc).timestamp()
    for hook, times in inv['cron_events'].items():
        if min(times) < now - 86400:
            problems.append(hook + ': 翌日を超えた未実行予定あり。定期処理の遅延の可能性。')
    if not inv['cron_events']:
        unknown.append('更新用cronイベントが見当たらない。実行履歴は未検証。')
    report += ['', '## 設定上の確認事項'] + ['- ' + text(p) for p in problems]
    if not problems:
        report.append('- この限定的な設定検査では指摘なし。')
    report += [f'- 設定ファイルの権限: {inv["config_mode"]}。共有ホストのACL等は未検証。',
               f'- MUプラグイン {len(inv["mu_plugin_files"])} 件 / drop-in {len(inv["dropins"])} 件。コード内部は未検査。',
               '- 二段階認証・WAF・バックアップ復元・メール到達・WebのPHP互換性は未検証。',
               '- Abletoneには既知のCSS直接変更あり。更新は保留。',
               '', '## 公開ページの防御設定']
    for route in ('/', '/contact/'):
        try:
            req = urllib.request.Request('https://goatmusic.jp' + route, headers={'User-Agent': 'GOATMusicReadOnlyAudit/1.0'})
            with urllib.request.urlopen(req, timeout=30) as response:
                missing = [h for h in ('Strict-Transport-Security', 'X-Content-Type-Options', 'Content-Security-Policy', 'X-Frame-Options', 'Referrer-Policy') if not response.headers.get(h)]
                report.append(f'- {route}: HTTP {response.status} / 未観測ヘッダー: ' + ', '.join(missing))
        except Exception as exc:
            unknown.append(route + ' 公開ページ取得: ' + type(exc).__name__)
    targets = [('core', inv['core'], inv['core'], True), ('php', inv['php_cli'], inv['php_cli'], True)]
    for kind in ('plugins', 'themes'):
        for slug, item in inv[kind].items():
            if kind == 'plugins' and '/' not in slug:
                unknown.append('単一ファイルプラグインの脆弱性slug未同定: ' + slug)
                continue
            targets.append((kind[:-1], slug.split('/')[0], item['Version'], item['active']))
    def vulnerability(target):
        kind, slug, version, active = target
        try:
            data = fetch('https://www.wpvulnerability.net/' + kind + '/' + urllib.parse.quote(slug, safe='') + '/')
            if data.get('error') or not isinstance(data.get('data'), dict):
                raise ValueError('no vulnerability dataset')
            content = data['data']
            findings = []
            if content.get('closed') in (1, '1', True):
                findings.append('公式ディレクトリで公開停止: ' + str(content.get('closed_reason')))
            for entry in vulnerability_records(content, kind):
                if affected(version, entry.get('operator', {})):
                    sources = [s.get('link', '') for s in entry.get('source', []) if s.get('link', '').startswith('https://')]
                    findings.append(entry.get('name', '名称不明') + ' / ' + ' '.join(sources[:2]))
            return target, findings, None
        except Exception as exc:
            return target, [], type(exc).__name__ + ': ' + str(exc)[:120]
    checked = 0
    with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
        for target, findings, error in pool.map(vulnerability, targets):
            kind, slug, version, active = target
            label = f'{kind}/{slug} {version} (active={active})'
            if error:
                unknown.append(label + ' 脆弱性照合未完了: ' + error)
            else:
                checked += 1
                matches.extend(label + ': ' + finding for finding in findings)
    report += ['', '## 既知脆弱性・公開停止の照合',
               '情報源: WPVulnerability の集約データ。バージョン範囲の一致は攻撃成功を意味しません。',
               f'照合完了: {checked}/{len(targets)} 対象。未検出でも安全性の保証ではありません。']
    report += ['- ' + text(m) for m in matches] or ['- 照合できた範囲では該当なし。']
    if any('whats-new-genarator' in m for m in matches):
        report += ['- CVE-2024-32548: 参照先のWordfence勧告では管理者権限が必要で、マルチサイトまたはunfiltered_html無効化の環境に限定。版の一致のみで攻撃可能とは判定しません。',
                   '- MULTISITE=' + str(flags.get('MULTISITE')) + ' / DISALLOW_UNFILTERED_HTML=' + str(flags.get('DISALLOW_UNFILTERED_HTML')) + '。権限フィルターの影響は未検査。']
    report += ['', '## 未確認・保留'] + ['- ' + text(u) for u in unknown]
    report += ['', '## 更新の判断', '- 今回の更新実行: 0件。',
               '- 互換性テストと復元可能性を確認できていないため、設定・プラグイン・テーマの変更は保留。',
               '- 外部データは誤りや未収録があり得るため、該当する個別の勧告・修正版を確認してから判断。',
               '- 未使用プラグインやテーマも検査対象。削除は実行していません。']
    print('\n'.join(report), flush=True)
    return 2 if unknown else (1 if matches else 0)


if __name__ == '__main__':
    sys.exit(main(sys.argv[1]))
