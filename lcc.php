
public function upload_pdf()
{
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

        return $this->output
            ->set_status_header(405)
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'status'  => false,
                'message' => 'Only POST method is allowed.'
            ]));
    }


    if (
        !isset($_FILES['files']) ||
        empty($_FILES['files']['name'])
    ) {

        return $this->output
            ->set_status_header(400)
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'status'  => false,
                'message' => 'No files received.'
            ]));
    }


    $files = $_FILES['files'];


    if (!is_array($files['name'])) {

        $files['name']     = [$files['name']];
        $files['tmp_name'] = [$files['tmp_name']];
        $files['error']    = [$files['error']];
        $files['size']     = [$files['size']];
    }


    $fileCount = count($files['name']);


    /*
     * PDF upload folder
     */
    $uploadPath = FCPATH . 'uploads/pdf/';


    /*
     * Create folder if it does not exist
     */
    if (!is_dir($uploadPath)) {

        if (!mkdir($uploadPath, 0777, true)) {

            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'status'  => false,
                    'message' => 'Unable to create uploads/pdf folder.'
                ]));
        }
    }


    /*
     * Check folder writable
     */
    if (!is_writable($uploadPath)) {

        return $this->output
            ->set_status_header(500)
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'status'  => false,
                'message' => 'uploads/pdf folder is not writable.'
            ]));
    }


    $conn = $this->db->conn_id;

    if (!$conn) {

        return $this->output
            ->set_status_header(500)
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'status'  => false,
                'message' => 'Oracle connection failed.'
            ]));
    }


    $dateFormatSql = "
        ALTER SESSION SET NLS_DATE_FORMAT = 'YY-MM-DD HH24:MI:SS'
    ";

    $dateFormatStmt = oci_parse(
        $conn,
        $dateFormatSql
    );

    if ($dateFormatStmt) {

        oci_execute(
            $dateFormatStmt,
            OCI_NO_AUTO_COMMIT
        );

        oci_free_statement($dateFormatStmt);
    }


    $batchSql = "
        SELECT LPAD(
            LCC_BATCH_ID_SEQ.NEXTVAL,
            3,
            '0'
        ) AS BATCH_ID
        FROM DUAL
    ";

    $batchStmt = oci_parse(
        $conn,
        $batchSql
    );


    if (!$batchStmt) {

        $errorInfo = oci_error($conn);

        return $this->output
            ->set_status_header(500)
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'status'  => false,
                'message' => 'Unable to prepare batch ID query.',
                'error'   => $errorInfo['message'] ?? ''
            ]));
    }


    if (!oci_execute($batchStmt)) {

        $errorInfo = oci_error($batchStmt);

        oci_free_statement($batchStmt);

        return $this->output
            ->set_status_header(500)
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'status'  => false,
                'message' => 'Unable to generate batch ID.',
                'error'   => $errorInfo['message'] ?? ''
            ]));
    }


    $batchRow = oci_fetch_assoc($batchStmt);

    oci_free_statement($batchStmt);


    if (!$batchRow || empty($batchRow['BATCH_ID'])) {

        return $this->output
            ->set_status_header(500)
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'status'  => false,
                'message' => 'Unable to generate batch ID.'
            ]));
    }


    $batch_id = $batchRow['BATCH_ID'];


    $uploaded_files = [];
    $failed_files   = [];


    for ($i = 0; $i < $fileCount; $i++) {

        $originalName = $files['name'][$i];
        $tmpName      = $files['tmp_name'][$i];
        $error        = $files['error'][$i];
        $fileSize     = $files['size'][$i];


        if ($error !== UPLOAD_ERR_OK) {

            $failed_files[] = [
                'file_name' => $originalName,
                'reason'    => 'File upload error. Error code: ' . $error
            ];

            continue;
        }


        if (!is_uploaded_file($tmpName)) {

            $failed_files[] = [
                'file_name' => $originalName,
                'reason'    => 'Invalid uploaded file.'
            ];

            continue;
        }


        $extension = strtolower(
            pathinfo(
                $originalName,
                PATHINFO_EXTENSION
            )
        );


        if ($extension !== 'pdf') {

            $failed_files[] = [
                'file_name' => $originalName,
                'reason'    => 'Only PDF files are allowed.'
            ];

            continue;
        }


        $finfo = finfo_open(FILEINFO_MIME_TYPE);


        if ($finfo === false) {

            $failed_files[] = [
                'file_name' => $originalName,
                'reason'    => 'Unable to validate PDF file type.'
            ];

            continue;
        }


        $mimeType = finfo_file(
            $finfo,
            $tmpName
        );


        finfo_close($finfo);


        if ($mimeType !== 'application/pdf') {

            $failed_files[] = [
                'file_name' => $originalName,
                'reason'    => 'Invalid PDF file.'
            ];

            continue;
        }


        $fileData = file_get_contents($tmpName);


        if ($fileData === false || strlen($fileData) === 0) {

            $failed_files[] = [
                'file_name' => $originalName,
                'reason'    => 'Unable to read PDF data.'
            ];

            continue;
        }


        if (substr($fileData, 0, 4) !== '%PDF') {

            $failed_files[] = [
                'file_name' => $originalName,
                'reason'    => 'Invalid PDF file content.'
            ];

            continue;
        }


        /*
         * Keep original file name safely
         */
        $safeFileName = preg_replace(
            '/[^A-Za-z0-9_\-\.]/',
            '_',
            $originalName
        );


        /*
         * Full physical file path
         */
        $filePath = $uploadPath . $safeFileName;


        /*
         * If same file name already exists,
         * create unique file name.
         */
        if (file_exists($filePath)) {

            $fileNameOnly = pathinfo(
                $safeFileName,
                PATHINFO_FILENAME
            );

            $fileExtension = pathinfo(
                $safeFileName,
                PATHINFO_EXTENSION
            );

            $safeFileName = $fileNameOnly
                . '_'
                . date('YmdHis')
                . '_'
                . uniqid()
                . '.'
                . $fileExtension;

            $filePath = $uploadPath . $safeFileName;
        }


        /*
         * Save PDF physically into uploads/pdf
         */
        if (!move_uploaded_file($tmpName, $filePath)) {

            $failed_files[] = [
                'file_name' => $originalName,
                'reason'    => 'Unable to store PDF in uploads/pdf folder.'
            ];

            continue;
        }


        /*
         * Oracle INSERT
         */
        $sql = "
            INSERT INTO LCC_BATCH
            (
                BATCH_ID,
                FILE_NAME,
                FILE_DATA,
                CREATED_DATE
            )
            VALUES
            (
                :batch_id,
                :file_name,
                EMPTY_BLOB(),
                SYSDATE
            )
            RETURNING FILE_DATA INTO :blob
        ";


        $stmt = oci_parse(
            $conn,
            $sql
        );


        if (!$stmt) {

            /*
             * Delete physical file if DB insert preparation fails
             */
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            $errorInfo = oci_error($conn);

            $failed_files[] = [
                'file_name' => $originalName,
                'reason'    => 'Unable to prepare Oracle INSERT.',
                'error'     => $errorInfo['message'] ?? ''
            ];

            continue;
        }


        $blob = oci_new_descriptor(
            $conn,
            OCI_D_LOB
        );


        if (!$blob) {

            /*
             * Delete physical file
             */
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            oci_free_statement($stmt);

            $failed_files[] = [
                'file_name' => $originalName,
                'reason'    => 'Unable to create Oracle BLOB descriptor.'
            ];

            continue;
        }


        oci_bind_by_name(
            $stmt,
            ':batch_id',
            $batch_id
        );


        oci_bind_by_name(
            $stmt,
            ':file_name',
            $originalName
        );


        oci_bind_by_name(
            $stmt,
            ':blob',
            $blob,
            -1,
            OCI_B_BLOB
        );


        $result = oci_execute(
            $stmt,
            OCI_NO_AUTO_COMMIT
        );


        if (!$result) {

            $errorInfo = oci_error($stmt);

            oci_rollback($conn);

            /*
             * Delete physical file if DB insert fails
             */
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            $blob->free();
            oci_free_statement($stmt);

            $failed_files[] = [
                'file_name' => $originalName,
                'reason'    => 'Oracle INSERT failed.',
                'error'     => $errorInfo['message'] ?? ''
            ];

            continue;
        }


        $blobWritten = $blob->save(
            $fileData
        );


        if (!$blobWritten) {

            $errorInfo = oci_error($conn);

            oci_rollback($conn);

            /*
             * Delete physical file if BLOB save fails
             */
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            $blob->free();
            oci_free_statement($stmt);

            $failed_files[] = [
                'file_name' => $originalName,
                'reason'    => 'Unable to save PDF into FILE_DATA.',
                'error'     => $errorInfo['message'] ?? ''
            ];

            continue;
        }


        $blobLength = $blob->size();


        if ($blobLength === false || $blobLength <= 0) {

            oci_rollback($conn);

            /*
             * Delete physical file if BLOB is empty
             */
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            $blob->free();
            oci_free_statement($stmt);

            $failed_files[] = [
                'file_name' => $originalName,
                'reason'    => 'FILE_DATA BLOB is empty.'
            ];

            continue;
        }


        if (!oci_commit($conn)) {

            $errorInfo = oci_error($conn);

            oci_rollback($conn);

            /*
             * Delete physical file if commit fails
             */
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            $blob->free();
            oci_free_statement($stmt);

            $failed_files[] = [
                'file_name' => $originalName,
                'reason'    => 'Oracle commit failed.',
                'error'     => $errorInfo['message'] ?? ''
            ];

            continue;
        }


        $blob->free();

        oci_free_statement($stmt);


        /*
         * Successfully stored in:
         * 1. uploads/pdf
         * 2. Oracle LCC_BATCH FILE_DATA
         */
        $uploaded_files[] = [
            'file_name' => $originalName,
            'stored_file_name' => $safeFileName,
            'file_path' => 'uploads/pdf/' . $safeFileName,
            'batch_id'  => $batch_id,
            'file_size' => $blobLength
        ];
    }


    $uploadedCount = count($uploaded_files);
    $failedCount   = count($failed_files);


    if ($uploadedCount === 0) {

        return $this->output
            ->set_status_header(400)
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'status'         => false,
                'message'        => 'No PDF files were stored in database.',
                'batch_id'       => $batch_id,
                'total_files'    => $fileCount,
                'uploaded_count' => 0,
                'failed_count'   => $failedCount,
                'uploaded_files' => [],
                'failed_files'   => $failed_files
            ]));
    }


    return $this->output
        ->set_status_header(200)
        ->set_content_type('application/json')
        ->set_output(json_encode([
            'status'         => true,
            'message'        => 'PDF files stored successfully in LCC_BATCH and uploads/pdf.',
            'batch_id'       => $batch_id,
            'total_files'    => $fileCount,
            'uploaded_count' => $uploadedCount,
            'failed_count'   => $failedCount,
            'uploaded_files' => $uploaded_files,
            'failed_files'   => $failed_files
        ]));
}

    public function test_LCC()
    {
        header('Content-Type: application/json');
    
        /*
         * ============================================================
         * 1. LCC PDF FOLDER
         * ============================================================
         */
    
        $folderPath = rtrim(FCPATH, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'pdf'
            . DIRECTORY_SEPARATOR;
    
        if (!is_dir($folderPath)) {
            echo json_encode([
                'status'  => false,
                'message' => 'Folder does not exist.',
                'path'    => $folderPath
            ]);
            return;
        }
    
        /*
         * ============================================================
         * 2. GET PDF FILES
         * ============================================================
         */
    
        $files = array_diff(scandir($folderPath), ['.', '..']);
    
        $files = array_values(array_filter($files, function ($file) use ($folderPath) {
            return is_file($folderPath . $file)
                && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf';
        }));
    
        if (empty($files)) {
            echo json_encode([
                'status'  => false,
                'message' => 'No PDF files found in uploads/pdf.'
            ]);
            return;
        }
    
        /*
         * ============================================================
         * 3. OCR API
         * ============================================================
         */
    
        $url = "http://172.16.0.181:6030/api/v1/lmw/lcc/data";
    
        $processed = 0;
        $failed    = 0;
    
        $processedFiles = [];
        $failedFiles    = [];
    
        /*
         * ============================================================
         * 4. PROCESS EACH PDF
         * ============================================================
         */
    
        foreach ($files as $file) {
    
            $filePath = $folderPath . $file;
    
            if (!is_file($filePath)) {
                $failed++;
    
                $failedFiles[] = [
                    'file_name' => $file,
                    'reason'    => 'File does not exist.'
                ];
    
                continue;
            }
    
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    
            if ($extension !== 'pdf') {
                $failed++;
    
                $failedFiles[] = [
                    'file_name' => $file,
                    'reason'    => 'Only PDF files are allowed.'
                ];
    
                continue;
            }
    
            /*
             * ========================================================
             * MIME TYPE
             * ========================================================
             */
    
            $mimeType = 'application/pdf';
    
            if (function_exists('mime_content_type')) {
                $detectedMime = mime_content_type($filePath);
    
                if (!empty($detectedMime)) {
                    $mimeType = $detectedMime;
                }
            }
    
            /*
             * ========================================================
             * CURL FILE
             * ========================================================
             */
    
            $cfile = new CURLFile(
                $filePath,
                $mimeType,
                basename($filePath)
            );
    
            /*
             * ========================================================
             * POST DATA
             * ========================================================
             */
    
            $postFields = [
                'invoice_id' => '123',
                'lcc_file'   => $cfile
            ];
    
            /*
             * ========================================================
             * 5. CALL OCR API
             * ========================================================
             */
    
            $ch = curl_init();
    
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $postFields,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_TIMEOUT        => 180,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json'
                ]
            ]);
    
            $response = curl_exec($ch);
    
            /*
             * --------------------------------------------------------
             * CURL ERROR
             * --------------------------------------------------------
             */
    
            if ($response === false) {
    
                $curlError = curl_error($ch);
    
                curl_close($ch);
    
                $failed++;
    
                $failedFiles[] = [
                    'file_name' => $file,
                    'reason'    => 'OCR API CURL error.',
                    'error'     => $curlError
                ];
    
                continue;
            }
    
            /*
             * --------------------------------------------------------
             * HTTP STATUS
             * --------------------------------------------------------
             */
    
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
            curl_close($ch);
    
            if ($httpCode < 200 || $httpCode >= 300) {
    
                $failed++;
    
                $failedFiles[] = [
                    'file_name' => $file,
                    'reason'    => 'OCR API returned HTTP error.',
                    'http_code' => $httpCode,
                    'response'  => $response
                ];
    
                continue;
            }
    
            /*
             * ========================================================
             * 6. DECODE JSON
             * ========================================================
             */
    
            $newResponse = json_decode($response, true);
    
            if (!is_array($newResponse)) {
    
                $failed++;
    
                $failedFiles[] = [
                    'file_name' => $file,
                    'reason'    => 'Invalid JSON response from OCR API.',
                    'response'  => $response
                ];
    
                continue;
            }
    
            /*
             * ========================================================
             * 7. CHECK RESULTS
             *
             * Actual API response:
             *
             * {
             *     "Failed_Count": 0,
             *     "Remarks": "All files processed successfully",
             *     "Results": [
             *         {
             *             "Bill_Of_Supply": {
             *                 "Header": {},
             *                 "Line_Items": [],
             *                 "Third_Party": []
             *             }
             *         }
             *     ]
             * }
             *
             * Do NOT check $newResponse['Status'] here.
             * ========================================================
             */
    
            if (
                !isset($newResponse['Results']) ||
                !is_array($newResponse['Results']) ||
                empty($newResponse['Results'])
            ) {
    
                $failed++;
    
                $failedFiles[] = [
                    'file_name' => $file,
                    'reason'    => 'OCR API returned no Results.',
                    'response'  => $newResponse
                ];
    
                continue;
            }
    
            $Results = $newResponse['Results'][0] ?? [];
    
            if (!is_array($Results) || empty($Results)) {
    
                $failed++;
    
                $failedFiles[] = [
                    'file_name' => $file,
                    'reason'    => 'OCR Result is empty.'
                ];
    
                continue;
            }
    
            /*
             * ========================================================
             * 8. DETECT DOCUMENT TYPE
             * ========================================================
             */
    
            $Document_Type = '';
    
            if (isset($Results['CAN'])) {
                $Document_Type = 'CAN';
    
            } elseif (isset($Results['EXPORT'])) {
                $Document_Type = 'EXPORT';
    
            } elseif (isset($Results['Bill_Of_Supply'])) {
                $Document_Type = 'BILL_OF_SUPPLY';
    
            } elseif (isset($Results['FTL_Division'])) {
                $Document_Type = 'FTL_DIVISION';
    
            } elseif (isset($Results['IMPORT'])) {
                $Document_Type = 'IMPORT';
    
            } elseif (isset($Results['Parcel_Division'])) {
                $Document_Type = 'PARCEL_DIVISION';
            }
    
            if ($Document_Type === '') {
    
                $failed++;
    
                $failedFiles[] = [
                    'file_name' => $file,
                    'reason'    => 'Unable to determine document type.',
                    'result'    => $Results
                ];
    
                continue;
            }
    
            /*
             * ========================================================
             * 9. COMMON VALUES
             * ========================================================
             */
    
            $fileNameFromApi = $Results['File_Name'] ?? $file;
            $fileNumber      = $Results['File_Number'] ?? '';
            $remarks         = $Results['Remarks'] ?? '';
            $status          = $Results['Status'] ?? '';
    
            /*
             * ========================================================
             * 10. CAN
             * ========================================================
             */
    
            if ($Document_Type === 'CAN') {
    
                $id_data = $this->common_model->findByQuery(
                    "SELECT LMWOCR_LCC_CAN_HEADER_SEQ.NEXTVAL AS ID FROM dual"
                );
    
                if (empty($id_data) || !isset($id_data[0]['ID'])) {
    
                    $failed++;
    
                    $failedFiles[] = [
                        'file_name' => $file,
                        'reason'    => 'Unable to generate CAN header ID.'
                    ];
    
                    continue;
                }
    
                $head_id = $id_data[0]['ID'];
    
                $header_row = $Results['CAN']['Header'] ?? [];
    
                $header_row['ID']          = $head_id;
                $header_row['File_Name']   = $fileNameFromApi;
                $header_row['File_Number'] = $fileNumber;
                $header_row['Remarks']     = $remarks;
                $header_row['Status']      = $status;
    
                $store_data = array_change_key_case(
                    $header_row,
                    CASE_UPPER
                );
    
                $this->common_model->store_details_oracle(
                    $store_data,
                    "LMWOCR_LCC_CAN_HEADER"
                );
    
                $detail_array = $Results['CAN']['Line_Items'] ?? [];
    
                if (is_array($detail_array)) {
    
                    foreach ($detail_array as $detail_rows) {
    
                        if (!is_array($detail_rows)) {
                            continue;
                        }
    
                        $detail_rows['header_id'] = $head_id;
    
                        $store_data_details = array_change_key_case(
                            $detail_rows,
                            CASE_UPPER
                        );
    
                        $this->common_model->store_details_oracle(
                            $store_data_details,
                            "LMWOCR_LCC_CAN_LINE"
                        );
                    }
                }
            }
    
            /*
             * ========================================================
             * 11. EXPORT
             * ========================================================
             */
    
            elseif ($Document_Type === 'EXPORT') {
    
                $id_data = $this->common_model->findByQuery(
                    "SELECT LMWOCR_LCC_EXPORT_HEADER_SEQ.NEXTVAL AS ID FROM dual"
                );
    
                if (empty($id_data) || !isset($id_data[0]['ID'])) {
    
                    $failed++;
    
                    $failedFiles[] = [
                        'file_name' => $file,
                        'reason'    => 'Unable to generate EXPORT header ID.'
                    ];
    
                    continue;
                }
    
                $head_id = $id_data[0]['ID'];
    
                $header_row = $Results['EXPORT']['Header'] ?? [];
    
                $header_row['ID']          = $head_id;
                $header_row['File_Name']   = $fileNameFromApi;
                $header_row['File_Number'] = $fileNumber;
                $header_row['Remarks']     = $remarks;
                $header_row['Status']      = $status;
    
                $store_data = array_change_key_case(
                    $header_row,
                    CASE_UPPER
                );
    
                $this->common_model->store_details_oracle(
                    $store_data,
                    "LMWOCR_LCC_EXPORT_HEADER"
                );
    
                $detail_array = $Results['EXPORT']['Line_Items'] ?? [];
    
                if (is_array($detail_array)) {
    
                    foreach ($detail_array as $detail_rows) {
    
                        if (!is_array($detail_rows)) {
                            continue;
                        }
    
                        $detail_rows['header_id'] = $head_id;
    
                        $store_data_details = array_change_key_case(
                            $detail_rows,
                            CASE_UPPER
                        );
    
                        $this->common_model->store_details_oracle(
                            $store_data_details,
                            "LMWOCR_LCC_EXPORT_LINE"
                        );
                    }
                }
            }
    
            /*
             * ========================================================
             * 12. BILL OF SUPPLY
             * ========================================================
             */
    
            elseif ($Document_Type === 'BILL_OF_SUPPLY') {
    
                $id_data = $this->common_model->findByQuery(
                    "SELECT LMWOCR_LCC_BILL_OF_SUPPLY_SEQ.NEXTVAL AS ID FROM dual"
                );
    
                if (empty($id_data) || !isset($id_data[0]['ID'])) {
    
                    $failed++;
    
                    $failedFiles[] = [
                        'file_name' => $file,
                        'reason'    => 'Unable to generate Bill Of Supply header ID.'
                    ];
    
                    continue;
                }
    
                $head_id = $id_data[0]['ID'];
    
                $header_row =
                    $Results['Bill_Of_Supply']['Header'] ?? [];
    
                $header_row['ID']          = $head_id;
                $header_row['File_Name']   = $fileNameFromApi;
                $header_row['File_Number'] = $fileNumber;
                $header_row['Remarks']     = $remarks;
                $header_row['Status']      = $status;
    
                if (isset($Results['Reference_Matched'])) {
                    $header_row['Reference_Matched'] =
                        $Results['Reference_Matched'];
                }
    
                $store_data = array_change_key_case(
                    $header_row,
                    CASE_UPPER
                );
    
                $this->common_model->store_details_oracle(
                    $store_data,
                    "LMWOCR_LCC_BILL_OF_SUPPLY_HEADER"
                );
    
                /*
                 * Line Items
                 */
    
                $detail_array =
                    $Results['Bill_Of_Supply']['Line_Items'] ?? [];
    
                if (is_array($detail_array)) {
    
                    foreach ($detail_array as $detail_rows) {
    
                        if (!is_array($detail_rows)) {
                            continue;
                        }
    
                        $detail_rows['header_id'] = $head_id;
    
                        $store_data_details = array_change_key_case(
                            $detail_rows,
                            CASE_UPPER
                        );
    
                        $this->common_model->store_details_oracle(
                            $store_data_details,
                            "LMWOCR_LCC_BILL_OF_SUPPLY_LINE"
                        );
                    }
                }
    
                /*
                 * Third Party
                 */
    
                $tp_array =
                    $Results['Bill_Of_Supply']['Third_Party'] ?? [];
    
                if (is_array($tp_array)) {
    
                    foreach ($tp_array as $tp_rows) {
    
                        if (!is_array($tp_rows)) {
                            continue;
                        }
    
                        $tp_rows['header_id'] = $head_id;
    
                        $store_data_tp = array_change_key_case(
                            $tp_rows,
                            CASE_UPPER
                        );
    
                        $this->common_model->store_details_oracle(
                            $store_data_tp,
                            "LMWOCR_LCC_BILL_OF_SUPPLY_THIRD_PARTY"
                        );
                    }
                }
            }
    
            /*
             * ========================================================
             * 13. FTL DIVISION
             * ========================================================
             */
    
            elseif ($Document_Type === 'FTL_DIVISION') {
    
                $id_data = $this->common_model->findByQuery(
                    "SELECT LMWOCR_LCC_FTL_DIVISION_SEQ.NEXTVAL AS ID FROM dual"
                );
    
                if (empty($id_data) || !isset($id_data[0]['ID'])) {
    
                    $failed++;
    
                    $failedFiles[] = [
                        'file_name' => $file,
                        'reason'    => 'Unable to generate FTL header ID.'
                    ];
    
                    continue;
                }
    
                $head_id = $id_data[0]['ID'];
    
                $header_row =
                    $Results['FTL_Division']['Header'] ?? [];
    
                $header_row['ID']          = $head_id;
                $header_row['File_Name']   = $fileNameFromApi;
                $header_row['File_Number'] = $fileNumber;
                $header_row['Remarks']     = $remarks;
                $header_row['Status']      = $status;
    
                $store_data = array_change_key_case(
                    $header_row,
                    CASE_UPPER
                );
    
                $this->common_model->store_details_oracle(
                    $store_data,
                    "LMWOCR_LCC_FTL_HEADER"
                );
    
                $detail_array =
                    $Results['FTL_Division']['Line_Items'] ?? [];
    
                if (is_array($detail_array)) {
    
                    foreach ($detail_array as $detail_rows) {
    
                        if (!is_array($detail_rows)) {
                            continue;
                        }
    
                        $detail_rows['header_id'] = $head_id;
    
                        $store_data_details = array_change_key_case(
                            $detail_rows,
                            CASE_UPPER
                        );
    
                        $this->common_model->store_details_oracle(
                            $store_data_details,
                            "LMWOCR_LCC_FTL_LINE"
                        );
                    }
                }
            }
    
            /*
             * ========================================================
             * 14. IMPORT
             * ========================================================
             */
    
            elseif ($Document_Type === 'IMPORT') {
    
                $id_data = $this->common_model->findByQuery(
                    "SELECT LMWOCR_LCC_IMPORTS_SEQ.NEXTVAL AS ID FROM dual"
                );
    
                if (empty($id_data) || !isset($id_data[0]['ID'])) {
    
                    $failed++;
    
                    $failedFiles[] = [
                        'file_name' => $file,
                        'reason'    => 'Unable to generate IMPORT header ID.'
                    ];
    
                    continue;
                }
    
                $head_id = $id_data[0]['ID'];
    
                $header_row =
                    $Results['IMPORT']['Header'] ?? [];
    
                $header_row['ID']          = $head_id;
                $header_row['File_Name']   = $fileNameFromApi;
                $header_row['File_Number'] = $fileNumber;
                $header_row['Remarks']     = $remarks;
                $header_row['Status']      = $status;
    
                $store_data = array_change_key_case(
                    $header_row,
                    CASE_UPPER
                );
    
                $this->common_model->store_details_oracle(
                    $store_data,
                    "LMWOCR_LCC_IMPORTS_HEADER"
                );
    
                $detail_array =
                    $Results['IMPORT']['Line_Items'] ?? [];
    
                if (is_array($detail_array)) {
    
                    foreach ($detail_array as $detail_rows) {
    
                        if (!is_array($detail_rows)) {
                            continue;
                        }
    
                        $detail_rows['header_id'] = $head_id;
    
                        $store_data_details = array_change_key_case(
                            $detail_rows,
                            CASE_UPPER
                        );
    
                        $this->common_model->store_details_oracle(
                            $store_data_details,
                            "LMWOCR_LCC_IMPORTS_LINE"
                        );
                    }
                }
            }
    
            /*
             * ========================================================
             * 15. PARCEL DIVISION
             * ========================================================
             */
    
            elseif ($Document_Type === 'PARCEL_DIVISION') {
    
                $id_data = $this->common_model->findByQuery(
                    "SELECT LMWOCR_LCC_PD_SEQ.NEXTVAL AS ID FROM dual"
                );
    
                if (empty($id_data) || !isset($id_data[0]['ID'])) {
    
                    $failed++;
    
                    $failedFiles[] = [
                        'file_name' => $file,
                        'reason'    => 'Unable to generate Parcel Division header ID.'
                    ];
    
                    continue;
                }
    
                $head_id = $id_data[0]['ID'];
    
                $header_row =
                    $Results['Parcel_Division']['Header'] ?? [];
    
                $header_row['ID']          = $head_id;
                $header_row['File_Name']   = $fileNameFromApi;
                $header_row['File_Number'] = $fileNumber;
                $header_row['Remarks']     = $remarks;
                $header_row['Status']      = $status;
    
                $store_data = array_change_key_case(
                    $header_row,
                    CASE_UPPER
                );
    
                $this->common_model->store_details_oracle(
                    $store_data,
                    "LMWOCR_LCC_PD_HEADER"
                );
    
                $detail_array =
                    $Results['Parcel_Division']['Line_Items'] ?? [];
    
                if (is_array($detail_array)) {
    
                    foreach ($detail_array as $detail_rows) {
    
                        if (!is_array($detail_rows)) {
                            continue;
                        }
    
                        $detail_rows['header_id'] = $head_id;
    
                        $store_data_details = array_change_key_case(
                            $detail_rows,
                            CASE_UPPER
                        );
    
                        $this->common_model->store_details_oracle(
                            $store_data_details,
                            "LMWOCR_LCC_PD_LINE"
                        );
                    }
                }
            }
    
            /*
             * ========================================================
             * 16. DELETE PDF AFTER SUCCESSFUL PROCESSING
             * ========================================================
             */
    
            if (is_file($filePath)) {
                unlink($filePath);
            }
    
            $processed++;
    
            $processedFiles[] = [
                'file_name'     => $file,
                'document_type' => $Document_Type,
                'file_number'   => $fileNumber,
                'remarks'       => $remarks
            ];
        }
    
        /*
         * ============================================================
         * 17. FINAL RESPONSE
         * ============================================================
         */
    
        echo json_encode([
            'status'          => true,
            'message'         => 'LCC processing completed.',
            'total_files'     => count($files),
            'processed'       => $processed,
            'failed'          => $failed,
            'processed_files' => $processedFiles,
            'failed_files'    => $failedFiles
        ]);
    }

}
