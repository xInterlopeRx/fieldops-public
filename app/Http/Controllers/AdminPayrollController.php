<?php

namespace App\Http\Controllers;

use App\Http\Requests\PayrollReportRequest;
use App\Models\TimeEntry;
use App\Services\PayrollReportService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AdminPayrollController extends Controller
{
    public function index(PayrollReportRequest $request, PayrollReportService $reports): InertiaResponse
    {
        $this->authorizePayrollReview();
        $filters = $request->validated();

        return Inertia::render('Admin/TimeTracking/Payroll', [
            'report' => $reports->build($filters['date_from'], $filters['date_to']),
            'filters' => $filters,
        ]);
    }

    public function exportCsv(PayrollReportRequest $request, PayrollReportService $reports): Response
    {
        $this->authorizePayrollReview();
        $filters = $request->validated();
        $report = $reports->build($filters['date_from'], $filters['date_to']);
        $stream = fopen('php://temp', 'r+');

        fputcsv($stream, [
            'Technician',
            'Regular Hours',
            'Overtime Hours',
            'Total Hours',
            'Included Status',
            'Date From',
            'Date To',
            'Workweek Starts',
            'Weekly Overtime Threshold Hours',
        ], ',', '"', '');

        foreach ($report['technicians'] as $technician) {
            fputcsv($stream, [
                $this->csvSafe((string) $technician['name']),
                $technician['regularHours'],
                $technician['overtimeHours'],
                $technician['totalHours'],
                'Approved',
                $report['dateFrom'],
                $report['dateTo'],
                $report['workweekStartsOn'],
                $report['overtimeThresholdHours'],
            ], ',', '"', '');
        }

        fputcsv($stream, [
            'TOTAL',
            $report['totals']['regularHours'],
            $report['totals']['overtimeHours'],
            $report['totals']['totalHours'],
            'Approved',
            $report['dateFrom'],
            $report['dateTo'],
            $report['workweekStartsOn'],
            $report['overtimeThresholdHours'],
        ], ',', '"', '');

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response($csv === false ? '' : $csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => $this->attachmentHeader(
                "payroll-summary-{$report['dateFrom']}-{$report['dateTo']}.csv",
            ),
        ]);
    }

    public function exportExcel(PayrollReportRequest $request, PayrollReportService $reports): Response
    {
        $this->authorizePayrollReview();
        $filters = $request->validated();
        $report = $reports->build($filters['date_from'], $filters['date_to']);
        $sheets = [
            'Summary' => $this->summarySheetRows($report),
            'Daily Totals' => $this->dailySheetRows($report),
            'Time Entries' => $this->entrySheetRows($report),
        ];

        return response($this->spreadsheetXml($sheets), 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => $this->attachmentHeader(
                "payroll-report-{$report['dateFrom']}-{$report['dateTo']}.xls",
            ),
        ]);
    }

    private function authorizePayrollReview(): void
    {
        Gate::authorize('reviewAny', TimeEntry::class);
    }

    /** @param array<string, mixed> $report
     * @return list<list<array{0: mixed, 1: string}>>
     */
    private function summarySheetRows(array $report): array
    {
        $rows = [[
            ['Technician', 'String'],
            ['Regular Hours', 'String'],
            ['Overtime Hours', 'String'],
            ['Total Hours', 'String'],
            ['Included Status', 'String'],
            ['Date From', 'String'],
            ['Date To', 'String'],
            ['Workweek Starts', 'String'],
            ['Weekly Overtime Threshold Hours', 'String'],
        ]];

        foreach ($report['technicians'] as $technician) {
            $rows[] = [
                [$technician['name'], 'String'],
                [$technician['regularHours'], 'Number'],
                [$technician['overtimeHours'], 'Number'],
                [$technician['totalHours'], 'Number'],
                ['Approved', 'String'],
                [$report['dateFrom'], 'String'],
                [$report['dateTo'], 'String'],
                [$report['workweekStartsOn'], 'String'],
                [$report['overtimeThresholdHours'], 'Number'],
            ];
        }

        $rows[] = [
            ['TOTAL', 'String'],
            [$report['totals']['regularHours'], 'Number'],
            [$report['totals']['overtimeHours'], 'Number'],
            [$report['totals']['totalHours'], 'Number'],
            ['Approved', 'String'],
            [$report['dateFrom'], 'String'],
            [$report['dateTo'], 'String'],
            [$report['workweekStartsOn'], 'String'],
            [$report['overtimeThresholdHours'], 'Number'],
        ];

        return $rows;
    }

    /** @param array<string, mixed> $report
     * @return list<list<array{0: mixed, 1: string}>>
     */
    private function dailySheetRows(array $report): array
    {
        $rows = [[
            ['Technician', 'String'],
            ['Date', 'String'],
            ['Regular Hours', 'String'],
            ['Overtime Hours', 'String'],
            ['Total Hours', 'String'],
        ]];

        foreach ($report['technicians'] as $technician) {
            foreach ($technician['days'] as $day) {
                $rows[] = [
                    [$technician['name'], 'String'],
                    [$day['date'], 'String'],
                    [$day['regularHours'], 'Number'],
                    [$day['overtimeHours'], 'Number'],
                    [$day['totalHours'], 'Number'],
                ];
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $report
     * @return list<list<array{0: mixed, 1: string}>>
     */
    private function entrySheetRows(array $report): array
    {
        $rows = [[
            ['Technician', 'String'],
            ['Date', 'String'],
            ['Time Entry ID', 'String'],
            ['Start', 'String'],
            ['End', 'String'],
            ['Customer', 'String'],
            ['Project', 'String'],
            ['Job', 'String'],
            ['Activity Type', 'String'],
            ['Billable', 'String'],
            ['Entry Hours', 'String'],
            ['Regular Hours', 'String'],
            ['Overtime Hours', 'String'],
        ]];

        foreach ($report['technicians'] as $technician) {
            foreach ($technician['days'] as $day) {
                foreach ($day['entries'] as $entry) {
                    $rows[] = [
                        [$technician['name'], 'String'],
                        [$entry['date'], 'String'],
                        [(string) $entry['id'], 'String'],
                        [$entry['startAt'], 'String'],
                        [$entry['endAt'], 'String'],
                        [$entry['customer'], 'String'],
                        [$entry['project'] ?? '', 'String'],
                        [$entry['job'] ?? '', 'String'],
                        [$entry['activityLabel'], 'String'],
                        [$entry['billable'] ? 'Yes' : 'No', 'String'],
                        [$entry['durationHours'], 'Number'],
                        [$entry['regularHours'], 'Number'],
                        [$entry['overtimeHours'], 'Number'],
                    ];
                }
            }
        }

        return $rows;
    }

    /** @param array<string, list<list<array{0: mixed, 1: string}>>> $sheets */
    private function spreadsheetXml(array $sheets): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<?mso-application progid="Excel.Sheet"?>'."\n"
            .'<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
            .'xmlns:o="urn:schemas-microsoft-com:office:office" '
            .'xmlns:x="urn:schemas-microsoft-com:office:excel" '
            .'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" '
            .'xmlns:html="http://www.w3.org/TR/REC-html40">'
            .'<Styles><Style ss:ID="Header"><Font ss:Bold="1"/></Style></Styles>';

        foreach ($sheets as $name => $rows) {
            $xml .= '<Worksheet ss:Name="'.$this->xmlEscape($name).'"><Table>';

            foreach ($rows as $rowIndex => $cells) {
                $xml .= '<Row>';

                foreach ($cells as [$value, $type]) {
                    $style = $rowIndex === 0 ? ' ss:StyleID="Header"' : '';
                    $cellValue = $type === 'Number'
                        ? (is_numeric($value) ? (string) $value : '0')
                        : $this->xmlEscape((string) $value);
                    $xml .= '<Cell'.$style.'><Data ss:Type="'.$type.'">'.$cellValue.'</Data></Cell>';
                }

                $xml .= '</Row>';
            }

            $xml .= '</Table></Worksheet>';
        }

        return $xml.'</Workbook>';
    }

    private function xmlEscape(string $value): string
    {
        $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function csvSafe(string $value): string
    {
        return preg_match('/^[\s]*[=+\-@]/u', $value) === 1 ? "'".$value : $value;
    }

    private function attachmentHeader(string $filename): string
    {
        return 'attachment; filename="'.$filename.'"';
    }
}
