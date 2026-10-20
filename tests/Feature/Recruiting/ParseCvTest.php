<?php

namespace Tests\Feature\Recruiting;

use Anthropic\Client;
use App\Models\User;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Selftest nhóm C (selftest.html:203-243). Không bao giờ gọi mạng thật. */
class ParseCvTest extends TestCase
{
    use RefreshDatabase;

    private const EN = "CURRICULUM VITAE\nTran Minh Hoang\nSenior Data Analyst at Tiki\nhoang.tran@gmail.com\n0912345678\n\nSUMMARY\n5 years of experience in analytics. SQL, Python, Power BI.";

    private const VI = "NGUYEN NGOC PHUONG TRANG\nChuyen vien Phan tich Du lieu\nEmail: trang.nguyen@acb.com.vn | Dien thoai: 0987 654 321\nKINH NGHIEM\n2.8 nam kinh nghiem tai ACB Bank. Thanh thao SQL, Excel.";

    private const JD = "JOB DESCRIPTION\nDigital Marketing Senior Lead (Content & Social)\nHeliDirect is hiring. Requirements: 5 years experience, Shopify knowledge. Benefits: salary.";

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.anthropic.key' => null]);
        $this->actingAs(User::factory()->role('hr')->create());
    }

    private function parse(string $text, array $extra = [])
    {
        return $this->postJson('/api/candidates/parse-cv', ['text' => $text] + $extra)->assertOk();
    }

    public function test_c1_english_cv(): void
    {
        $r = $this->parse(self::EN)->assertJsonPath('engine', 'rules')->assertJsonPath('not_a_cv', false);
        $c = $r->json('candidate');
        $this->assertSame('Tran Minh Hoang', $c['full_name']);
        $this->assertSame('hoang.tran@gmail.com', $c['email']);
        $this->assertSame('0912345678', $c['phone']);
        $this->assertEquals(5, $c['years_experience']);
        $this->assertSame('Senior Data Analyst', $c['current_title']);
        $this->assertSame('Tiki', $c['current_company']);
        foreach (['SQL', 'Python', 'Power BI'] as $s) {
            $this->assertContains($s, $c['skills']);
        }
        $this->assertNotEmpty($r->json('build'));
    }

    public function test_c2_vietnamese_cv(): void
    {
        $c = $this->parse(self::VI)->json('candidate');
        $this->assertSame('NGUYEN NGOC PHUONG TRANG', $c['full_name']);
        $this->assertSame(2.8, $c['years_experience']);
        $this->assertSame('0987654321', $c['phone']);
        $this->assertSame('trang.nguyen@acb.com.vn', $c['email']);
    }

    public function test_c3_job_description_is_not_a_cv(): void
    {
        $r = $this->parse(self::JD)->assertJsonPath('not_a_cv', true);
        $this->assertEmpty($r->json('candidate.full_name'));
    }

    public function test_c4_skills_match_whole_words_only(): void
    {
        $text = "Tran Van B\nban@example.com\nDigital marketing executive at Google. Experience with Excel and SEO reporting. 4 years experience.";
        $c = $this->parse($text)->json('candidate');
        $this->assertSame([], array_values(array_intersect(array_map('strtolower', $c['skills']), ['r', 'go', 'git', 'java'])));
        $this->assertContains('Excel', $c['skills']);
        $this->assertContains('SEO', $c['skills']);
        $this->assertSame('Tran Van B', $c['full_name']);
        $this->assertEquals(4, $c['years_experience']);
        $this->assertNotContains('Java', $this->parse("Nguyen Van A\nJavaScript developer")->json('candidate.skills'));
    }

    public function test_c5_empty_or_short_text_is_rejected(): void
    {
        $this->postJson('/api/candidates/parse-cv', ['text' => 'abc'])->assertStatus(400);
        $this->postJson('/api/candidates/parse-cv', ['text' => "  \n "])->assertStatus(400);
        $this->postJson('/api/candidates/parse-cv', [])->assertStatus(422);
    }

    public function test_phone_does_not_swallow_digits_of_the_next_line_or_number(): void
    {
        $this->assertSame('0912345678', $this->parse("Tran Minh Hoang\n0912345678\n5 years of experience")->json('candidate.phone'));
        $this->assertSame('0912345678', $this->parse("Tran Minh Hoang\nĐT: 0912 345 678 2 năm kinh nghiệm")->json('candidate.phone'));
        $this->assertSame('02812345678', $this->parse("Tran Minh Hoang\nTel: (028) 1234 5678")->json('candidate.phone'));
    }

    public function test_labelled_vietnamese_fields_and_plus84_phone(): void
    {
        $c = $this->parse("SƠ YẾU LÝ LỊCH\nHọ và tên: Lê Thị Mỹ Duyên\nĐiện thoại: +84 912 345 678\nChức danh: Kế toán tổng hợp\nCông ty: MISA\nKinh nghiệm: 3 năm", ['filename' => 'TopCV-LeThiMyDuyen.pdf'])->json('candidate');
        $this->assertSame('Lê Thị Mỹ Duyên', $c['full_name']);
        $this->assertSame('0912345678', $c['phone']);
        $this->assertSame('Kế toán tổng hợp', $c['current_title']);
        $this->assertSame('MISA', $c['current_company']);
        $this->assertEquals(3, $c['years_experience']);
        $this->assertSame('TopCV', $c['source']);
    }

    /**
     * Thay transporter của SDK bằng Guzzle MockHandler: không gọi mạng, ghi lại request đã gửi.
     *
     * @param  array<int, array{0:int,1:array}>  $responses  [status, body]
     */
    private function fakeClaude(array $responses, array &$history): void
    {
        $mock = new MockHandler(array_map(
            fn ($r) => new Response($r[0], ['Content-Type' => 'application/json'], json_encode($r[1])),
            $responses,
        ));
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $this->app->bind(Client::class, fn () => new Client(
            apiKey: 'sk-test',
            requestOptions: ['maxRetries' => 0, 'transporter' => new Guzzle(['handler' => $stack])],
        ));
    }

    private static function message(array $content, string $stop = 'end_turn'): array
    {
        return [
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => $content, 'stop_reason' => $stop, 'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ];
    }

    public function test_ai_engine_with_faked_anthropic_api(): void
    {
        config(['services.anthropic.key' => 'sk-test', 'services.anthropic.cv_model' => 'claude-opus-5-5']);
        $history = [];
        $this->fakeClaude([[200, self::message([
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
            ['type' => 'text', 'text' => json_encode([
                'is_cv' => true, 'full_name' => 'Tran Minh Hoang', 'email' => 'hoang.tran@gmail.com',
                'phone' => '+84 912 345 678', 'current_title' => 'Senior Data Analyst', 'current_company' => 'Tiki',
                'years_experience' => 5, 'source' => null, 'skills' => ['SQL', 'Python', 'SQL', ''],
            ])],
        ])]], $history);

        $r = $this->parse(self::EN, ['filename' => 'cv.pdf'])->assertJsonPath('engine', 'ai')->assertJsonPath('not_a_cv', false);
        $this->assertSame('0912345678', $r->json('candidate.phone'));
        $this->assertSame(['SQL', 'Python'], $r->json('candidate.skills'));
        $this->assertSame('Tran Minh Hoang', $r->json('candidate.full_name'));

        $this->assertCount(1, $history);
        $req = $history[0]['request'];
        $body = json_decode((string) $req->getBody(), true);
        $this->assertStringEndsWith('/v1/messages', $req->getUri()->getPath());
        $this->assertSame('sk-test', $req->getHeaderLine('x-api-key'));
        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertSame('low', $body['output_config']['effort']);
        $this->assertStringContainsString('Tran Minh Hoang', $body['messages'][0]['content']);
    }

    public function test_ai_says_not_a_cv(): void
    {
        config(['services.anthropic.key' => 'sk-test']);
        $history = [];
        $this->fakeClaude([[200, self::message([
            ['type' => 'text', 'text' => '{"is_cv": false, "full_name": null, "email": null, "phone": null, "current_title": null, "current_company": null, "years_experience": null, "source": null, "skills": []}'],
        ])]], $history);
        $this->parse(self::JD)->assertJsonPath('engine', 'ai')->assertJsonPath('not_a_cv', true)->assertJsonPath('candidate.full_name', null);
    }

    public function test_falls_back_to_rules_when_ai_fails(): void
    {
        config(['services.anthropic.key' => 'sk-test']);
        $history = [];
        $this->fakeClaude([
            [529, ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]],
            [200, self::message([['type' => 'text', 'text' => 'không phải JSON']])],
            [200, self::message([], 'refusal')],
        ], $history);
        foreach (range(1, 3) as $_) {
            $this->parse(self::EN)->assertJsonPath('engine', 'rules')->assertJsonPath('candidate.full_name', 'Tran Minh Hoang');
        }
        $this->assertCount(3, $history);
    }
}
