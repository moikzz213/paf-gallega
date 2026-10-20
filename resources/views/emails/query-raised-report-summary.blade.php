<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; line-height: 1.5; }
        .container { max-width: 800px; margin: 0 auto; padding: 20px; }
        .header { background-color: #1f2937; color: #fff; padding: 20px; border-radius: 4px 4px 0 0; }
        .body { background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .footer { background-color: #eee; padding: 15px; border-radius: 0 0 4px 4px; font-size: 12px; color: #666; }
        .warn { background:#fef2f2; border:1px solid #fca5a5; padding:12px; border-radius:4px; margin:12px 0; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
        th { background: #f3f4f6; text-align: left; padding: 6px 8px; border: 1px solid #ddd; }
        td { padding: 6px 8px; border: 1px solid #ddd; vertical-align: top; }
        .num { text-align: right; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin:0;">Weekly Query Raised Report &mdash; Summary</h2>
        </div>
        <div class="body">
            <p>This is what the weekly Query Raised Report sent on {{ $reportDate }}.</p>

            @if (! $sent && ! $withoutHead && ! $unmatched)
                <p><strong>No department had open queries this week</strong>, so no reports were sent.</p>
            @endif

            @if ($sent)
                <table>
                    <thead>
                        <tr><th>Department</th><th>Sent to</th><th class="num">Open queries</th><th>Result</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($sent as $line)
                            <tr>
                                <td>{{ $line['department'] }}</td>
                                <td>{{ implode(', ', $line['recipients']) }}</td>
                                <td class="num">{{ $line['count'] }}</td>
                                <td>{{ $line['sent'] ? 'Sent' : 'Could not be sent — will need to be re-run' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if ($withoutHead)
                <div class="warn">
                    <strong>Not sent — no Department Head nominated.</strong> These departments have open
                    queries, but no active Department Head in Master Data, so nobody received their report:
                    <ul>
                        @foreach ($withoutHead as $line)
                            <li>{{ $line['department'] }} ({{ $line['count'] }} open {{ $line['count'] === 1 ? 'query' : 'queries' }})</li>
                        @endforeach
                    </ul>
                    An administrator can nominate Heads under Master Data &rarr; Departments.
                </div>
            @endif

            @if ($unmatched)
                <div class="warn">
                    <strong>Not sent — department not in Master Data.</strong> These invoices name a department
                    that does not match any department in Master Data, so nobody received them:
                    <ul>
                        @foreach ($unmatched as $line)
                            <li>{{ $line['department'] }} ({{ $line['count'] }} open {{ $line['count'] === 1 ? 'query' : 'queries' }})</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
        <div class="footer">
            This is an automated summary from the Invoice Payment Approval Platform, sent to Finance and
            administrators after each weekly Query Raised Report.
        </div>
    </div>
</body>
</html>
