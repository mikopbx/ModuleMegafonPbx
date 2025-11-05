<?php
/*
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 8 2020
 */

namespace Modules\ModuleMegafonPbx\Lib\RestAPI;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\Workers\WorkerCdr;
use MikoPBX\PBXCoreREST\Controllers\BaseController;
use DateTime;

class GetController extends BaseController
{
    /**
     * Последовательная загрузка данных из cdr таблицы.
     * /pbxcore/api/cdr/getData MIKO AJAM
     * curl 'http://127.0.0.1:80/mega-pbx/api/cdr?offset=0&limit=1';
     */
    public function getDataAction(): void
    {
        $offset = $this->request->get('offset');
        $limit  = $this->request->get('limit');
        $limit  = ($limit > 600) ? 600 : $limit;
        $maxOffset = 0;

        $filter = [
            'id>:id:',
            'bind'                => ['id' => $offset],
            'order'               => 'id',
            'limit'               => $limit,
            'miko_result_in_file' => true,
        ];

        $client  = new BeanstalkClient(WorkerCdr::SELECT_CDR_TUBE);
        $message = $client->request(json_encode($filter), 5);
        if ($message === false) {
            $this->response->setContent('');
            $this->response->setHeader('X-STATE', 'FAIL-CDR-TUBE');

        } else {
            $result   = json_decode($message, true);
            $arr_data = [];
            if (is_string($result) && file_exists($result)) {
                $arr_data = json_decode(file_get_contents($result), true);
                @unlink($result);
            }
            $xml_output = "<history>".PHP_EOL;
            if (is_array($arr_data)) {
                foreach ($arr_data as $data) {
                    $maxOffset = max($maxOffset, $data['id']);
                    if(!str_starts_with($data['linkedid'],'fs-megapbx-')){
                        continue;
                    }
                    $xml_output .= "<history_record no=\"$data[linkedid]\" entire_id=\"$data[linkedid]\" line=\"$data[did]\">".PHP_EOL;
                    $detailAttr = [
                        'call_id' => $data['linkedid'],
                        'status'  => $data['disposition'] === 'ANSWERED'?'ANSWER':'CANCEL',
                        'call_flow' => '',
                        'queue' => '',
                        'start' => $data['start'],
                        'started' => (new DateTime($data['start']))->format('c'),
                        'answered' => (new DateTime($data['answer']))->format('c'),
                        'finished' => (new DateTime($data['endtime']))->format('c'),
                        'duration' => $data['duration'],
                        'conversation' => $data['billsec'],
                        'record_file' => $data['recordingfile'],
                        'finish_cause' => 'Normal Clearing',
                    ];
                    $attributesDetail = '';
                    foreach ($detailAttr as $tmp_key => $tmp_val) {
                        $attributesDetail .= sprintf('%s="%s" ', $tmp_key, $tmp_val);
                    }
                    $xml_output .= "<details $attributesDetail />".PHP_EOL;
                    $xml_output .= "<from ext=\"\" number=\"$data[src_num]\"></from>".PHP_EOL;
                    $xml_output .= "<to ext=\"\" number=\"$data[dst_num]\"></to>".PHP_EOL;
                    $xml_output .= '</history_record>';
                }
            }
            $xml_output .= '</history>';

            $this->response->setContent($xml_output);
        }

        $this->response->setHeader('X-MIN-OFFSET', $offset);
        $this->response->setHeader('X-MAX-OFFSET', max($maxOffset, $offset));
        $this->response->sendRaw();
    }

}