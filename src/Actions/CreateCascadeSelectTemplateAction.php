<?php

namespace Lalalili\SurveyCore\Actions;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class CreateCascadeSelectTemplateAction
{
    /**
     * @return list<list<string>>
     */
    public function rows(): array
    {
        return [
            ['縣市', '鄉鎮區'],
            ['台北市', '中正區'],
            ['台北市', '大安區'],
            ['台北市', '信義區'],
            ['新北市', '板橋區'],
            ['新北市', '新莊區'],
            ['新北市', '三重區'],
        ];
    }

    public function writeToPath(string $path): void
    {
        $writer = new Writer();
        $writer->openToFile($path);

        foreach ($this->rows() as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();
    }
}
