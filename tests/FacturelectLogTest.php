<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/class/facturelectlog.class.php';

class FacturelectLogTest extends TestCase
{
	private $db;

	protected function setUp(): void
	{
		$this->db = new DoliDB();
		global $user;
		$user = new User();
		$user->id = 123;
	}

	protected function tearDown(): void
	{
		global $user;
		$user = null;
	}

	public function testLogArrayPayloadsConvertsToJsonInSql()
	{
		$requestData = array('foo' => 'bar');
		$responseData = array('status' => 'success');

		$res = FacturelectLog::log(
			$this->db,
			'SuperPDP',
			'test_action',
			'https://api.test/v1/action',
			'POST',
			200,
			$requestData,
			$responseData
		);

		$this->assertEquals(42, $res); // Fictional mock ID
		$this->assertCount(1, $this->db->queries);

		$query = $this->db->last_query;
		$this->assertStringContainsString('INSERT INTO llx_facturelect_log', $query);
		$this->assertStringContainsString("'SuperPDP'", $query);
		$this->assertStringContainsString("'test_action'", $query);
		$this->assertStringContainsString("'POST'", $query);
		$this->assertStringContainsString('200', $query);
		$this->assertStringContainsString('123', $query); // user ID
		
		// The array payloads should be converted to JSON strings
		$this->assertStringContainsString('{\\"foo\\":\\"bar\\"}', $query);
		$this->assertStringContainsString('{\\"status\\":\\"success\\"}', $query);
	}

	public function testLogPdfPayloadDetectsAndTruncates()
	{
		$pdfData = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog ... >>\nstream\n...binary content...\nendstream";
		
		FacturelectLog::log(
			$this->db,
			'SuperPDP',
			'download_pdf',
			'https://api.test/v1/pdf',
			'GET',
			200,
			'',
			$pdfData
		);

		$query = $this->db->last_query;
		// The PDF binary content should be replaced by a size description string
		$expectedPlaceholder = '<Binary PDF data: ' . strlen($pdfData) . ' bytes>';
		$this->assertStringContainsString($this->db->escape($expectedPlaceholder), $query);
	}

	public function testLogLargePayloadTruncates()
	{
		// 15000 character long string
		$largeData = str_repeat('A', 15000);
		
		FacturelectLog::log(
			$this->db,
			'SuperPDP',
			'large_call',
			'https://api.test/v1/large',
			'POST',
			200,
			$largeData,
			'response'
		);

		$query = $this->db->last_query;
		$expectedPlaceholder = '<Large or Binary payload: 15000 bytes>';
		$this->assertStringContainsString($this->db->escape($expectedPlaceholder), $query);
	}
}
