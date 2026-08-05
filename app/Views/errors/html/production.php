<!DOCTYPE html>
<!--
    500 류 에러 화면. (#114)

    이 파일은 장애 상황에 렌더된다. 그래서 아무것도 믿지 않는다:
    뷰 헬퍼도, 공용 레이아웃도(그 안의 partials/header 가 Shield 로 DB 를 친다),
    설정·URL·언어 헬퍼도, 외부 CSS·웹폰트도 일절 쓰지 않는다. 부트 실패나 DB
    장애가 원인일 때 그런 호출은 에러 페이지를 그리다가 2차 예외를 낸다.

    (헬퍼 이름을 괄호까지 적어 두면 이 주석 자체가 아래 계약 검사에 걸린다.)

    색은 app.css 의 팔레트와 같은 값을 하드코딩했다(CSS 변수는 외부 파일에 있다).
    이 계약은 tests/Feature/ErrorPageTest.php 가 지킨다.
-->
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>일시적인 문제가 생겼습니다</title>
    <style>
        :root { color-scheme: light; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #F0F0F0;
            color: #2E3440;
            font-family: Pretendard, -apple-system, BlinkMacSystemFont, system-ui, "Segoe UI", sans-serif;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }
        .wrap {
            max-width: 480px;
            margin: 24px;
            padding: 48px 32px;
            background: #ffffff;
            border: 1px solid #D8DEE9;
            border-radius: 12px;
            text-align: center;
        }
        .code {
            margin: 0;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 56px;
            font-weight: 600;
            line-height: 1;
            letter-spacing: -0.02em;
            color: #9AA5B5;
        }
        h1 {
            margin: 16px 0 8px;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }
        p.desc {
            margin: 0 0 28px;
            color: #4C566A;
            font-size: 15px;
        }
        a.home {
            display: inline-flex;
            align-items: center;
            padding: 9px 18px;
            border-radius: 999px;
            background: #5E81AC;
            color: #ffffff;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <main class="wrap">
        <p class="code">500</p>
        <h1>일시적인 문제가 생겼습니다</h1>
        <p class="desc">
            잠시 후 다시 시도해 주세요.<br>
            문제가 계속되면 조금 뒤에 방문해 주시면 감사하겠습니다.
        </p>
        <a class="home" href="/">홈으로</a>
    </main>
</body>
</html>
