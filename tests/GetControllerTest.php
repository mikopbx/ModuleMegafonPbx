<?php

declare(strict_types=1);

namespace MikoPBX\Core\System {
    final class BeanstalkClient
    {
        public function __construct(string $tube)
        {
        }

        public function request(string $payload, int $timeout): string
        {
            $path = tempnam(sys_get_temp_dir(), 'megapbx-cdr-test-');
            file_put_contents($path, json_encode([[
                'id' => 2012265,
                'linkedid' => 'fs-megapbx-1786620879.FM0CF0I1UC000043',
                'did' => '79323095558',
                'disposition' => 'ANSWERED',
                'start' => '2026-08-13 11:34:39.000000',
                'answer' => '2026-08-13 11:35:16.000000',
                'endtime' => '2026-08-13 11:40:31.000000',
                'duration' => 315,
                'billsec' => 278,
                'recordingfile' => '/records/call.mp3',
                'src_num' => '73472493526',
                'dst_num' => '79323095558',
            ]], JSON_UNESCAPED_SLASHES));

            return json_encode($path);
        }
    }
}

namespace MikoPBX\Core\Workers {
    final class WorkerCdr
    {
        public const SELECT_CDR_TUBE = 'select-cdr';
    }
}

namespace MikoPBX\PBXCoreREST\Controllers {
    class BaseController
    {
        public $request;
        public $response;
    }
}

namespace Modules\ModuleMegafonPbx\Tests {
    final class RequestStub
    {
        public function get(string $name)
        {
            return $name === 'offset' ? 2012264 : 1;
        }
    }

    final class ResponseStub
    {
        public string $content = '';
        public array $headers = [];

        public function setContent(string $content): void
        {
            $this->content = $content;
        }

        public function setHeader(string $name, $value): void
        {
            $this->headers[$name] = $value;
        }

        public function sendRaw(): void
        {
        }
    }

    require dirname(__DIR__) . '/Lib/RestAPI/GetController.php';

    date_default_timezone_set('Europe/Moscow');
    $controller = new \Modules\ModuleMegafonPbx\Lib\RestAPI\GetController();
    $controller->request = new RequestStub();
    $controller->response = new ResponseStub();
    $controller->getDataAction();

    $document = new \DOMDocument();
    if (!$document->loadXML($controller->response->content)) {
        throw new \RuntimeException('Controller returned invalid XML');
    }

    $record = $document->getElementsByTagName('history_record')->item(0);
    $details = $document->getElementsByTagName('details')->item(0);
    $expected = [
        'line' => '79323095558',
        'line_number' => '79323095558',
        'duration' => '315',
        'conversation' => '278',
    ];
    $actual = [
        'line' => $record->getAttribute('line'),
        'line_number' => $record->getAttribute('line_number'),
        'duration' => $details->getAttribute('duration'),
        'conversation' => $details->getAttribute('conversation'),
    ];

    if ($actual !== $expected) {
        fwrite(STDERR, 'FAIL: ' . json_encode(['expected' => $expected, 'actual' => $actual]) . PHP_EOL);
        exit(1);
    }

    fwrite(STDOUT, "PASS: MegaPBX CDR XML preserves line and durations\n");
}
