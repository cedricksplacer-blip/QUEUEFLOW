<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>QueueFlow TMC — Account Verification</title>

    @vite([
        'resources/css/app.css',
        'resources/js/app.js'
    ])

    <style>

        /* =========================================================
           QUEUEFLOW TMC
           CRYSTAL COMMAND EMAIL VERIFICATION
           MATCHED WITH ADMIN LOGIN DESIGN
        ========================================================= */

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --navy: #0B172A;
            --navy-2: #14213D;
            --dark: #172033;
            --slate: #475569;
            --muted: #64748B;
            --light-muted: #94A3B8;

            --blue: #1264D8;
            --blue-dark: #073B91;

            --cyan: #007F95;
            --cyan-light: #00B8D4;

            --green: #00875A;
            --green-light: #00C987;

            --violet: #6948D8;

            --white: #FFFFFF;

            --glass: rgba(255, 255, 255, .86);
            --glass-border: rgba(15, 23, 42, .13);
        }


        /* =========================================================
           PAGE
        ========================================================= */

        html,
        body {
            width: 100%;
            min-height: 100%;
            margin: 0;
        }

        body {
            font-family:
                Inter,
                "Segoe UI",
                Roboto,
                Arial,
                sans-serif;

            color: var(--dark);

            background: #020713;

            overflow: hidden;
        }


        /* =========================================================
           MAIN SCENE
        ========================================================= */

        .scene {
            position: relative;

            width: 100%;
            min-height: 100vh;

            display: flex;
            align-items: center;
            justify-content: center;

            overflow: hidden;

            background:
                radial-gradient(
                    circle at 12% 15%,
                    rgba(0, 184, 212, .22),
                    transparent 28%
                ),

                radial-gradient(
                    circle at 90% 80%,
                    rgba(105, 72, 216, .22),
                    transparent 30%
                ),

                radial-gradient(
                    circle at 50% 45%,
                    rgba(18, 100, 216, .13),
                    transparent 35%
                ),

                linear-gradient(
                    135deg,
                    #01040B,
                    #03101F,
                    #070313
                );
        }


        /* =========================================================
           BACKGROUND GRID
        ========================================================= */

        .grid {
            position: absolute;
            inset: -50%;

            pointer-events: none;

            opacity: .35;

            background-image:
                linear-gradient(
                    rgba(0, 184, 212, .055) 1px,
                    transparent 1px
                ),

                linear-gradient(
                    90deg,
                    rgba(0, 184, 212, .055) 1px,
                    transparent 1px
                );

            background-size: 55px 55px;

            transform:
                perspective(600px)
                rotateX(62deg)
                translateY(100px);

            animation:
                gridMove
                18s
                linear
                infinite;
        }

        @keyframes gridMove {

            from {
                background-position:
                    0 0,
                    0 0;
            }

            to {
                background-position:
                    0 55px,
                    55px 0;
            }
        }


        /* =========================================================
           AURORA
        ========================================================= */

        .aurora {
            position: absolute;

            border-radius: 50%;

            pointer-events: none;

            filter: blur(80px);

            mix-blend-mode: screen;
        }

        .aurora.a {
            width: 480px;
            height: 480px;

            top: -170px;
            left: -150px;

            background:
                radial-gradient(
                    circle,
                    rgba(0, 234, 255, .40),
                    rgba(18, 100, 216, .16),
                    transparent 70%
                );

            animation:
                auroraA
                14s
                ease-in-out
                infinite;
        }

        .aurora.b {
            width: 550px;
            height: 550px;

            right: -220px;
            bottom: -190px;

            background:
                radial-gradient(
                    circle,
                    rgba(105, 72, 216, .38),
                    rgba(255, 69, 212, .10),
                    transparent 70%
                );

            animation:
                auroraB
                17s
                ease-in-out
                infinite;
        }

        .aurora.c {
            width: 330px;
            height: 330px;

            left: 45%;
            top: -150px;

            background:
                radial-gradient(
                    circle,
                    rgba(0, 234, 255, .20),
                    transparent 70%
                );

            animation:
                auroraC
                11s
                ease-in-out
                infinite;
        }

        @keyframes auroraA {

            0%,
            100% {
                transform:
                    translate(0, 0)
                    scale(1);
            }

            50% {
                transform:
                    translate(190px, 140px)
                    scale(1.3);
            }
        }

        @keyframes auroraB {

            0%,
            100% {
                transform:
                    translate(0, 0)
                    scale(1);
            }

            50% {
                transform:
                    translate(-190px, -150px)
                    scale(1.3);
            }
        }

        @keyframes auroraC {

            0%,
            100% {
                transform:
                    translateX(0)
                    scale(.8);

                opacity: .35;
            }

            50% {
                transform:
                    translateX(190px)
                    scale(1.4);

                opacity: .9;
            }
        }


        /* =========================================================
           ORBITS
        ========================================================= */

        .orbit {
            position: absolute;

            left: 50%;
            top: 50%;

            border-radius: 50%;

            transform:
                translate(-50%, -50%);

            pointer-events: none;
        }

        .orbit.one {
            width: 720px;
            height: 720px;

            border:
                1px solid
                rgba(0, 234, 255, .08);

            animation:
                orbitPulse
                8s
                ease-in-out
                infinite;
        }

        .orbit.two {
            width: 980px;
            height: 980px;

            border:
                1px solid
                rgba(105, 72, 216, .06);

            animation:
                orbitPulse
                12s
                ease-in-out
                infinite
                reverse;
        }

        .orbit.three {
            width: 1250px;
            height: 1250px;

            border:
                1px solid
                rgba(255, 69, 212, .035);
        }

        @keyframes orbitPulse {

            0%,
            100% {
                transform:
                    translate(-50%, -50%)
                    scale(.96);

                opacity: .35;
            }

            50% {
                transform:
                    translate(-50%, -50%)
                    scale(1.05);

                opacity: 1;
            }
        }


        /* =========================================================
           PARTICLES
        ========================================================= */

        .particles {
            position: absolute;
            inset: 0;

            pointer-events: none;
        }

        .particle {
            position: absolute;

            width: 3px;
            height: 3px;

            border-radius: 50%;

            background:
                var(--cyan-light);

            box-shadow:
                0 0 8px var(--cyan-light),
                0 0 18px var(--cyan-light);

            opacity: .5;

            animation:
                particleFloat
                var(--speed)
                linear
                infinite;
        }

        .particle:nth-child(1) {
            left: 7%;
            top: 82%;
            --speed: 9s;
        }

        .particle:nth-child(2) {
            left: 17%;
            top: 45%;
            --speed: 13s;
        }

        .particle:nth-child(3) {
            left: 27%;
            top: 72%;
            --speed: 15s;
        }

        .particle:nth-child(4) {
            left: 38%;
            top: 20%;
            --speed: 11s;
        }

        .particle:nth-child(5) {
            left: 49%;
            top: 82%;
            --speed: 16s;
        }

        .particle:nth-child(6) {
            left: 61%;
            top: 24%;
            --speed: 12s;
        }

        .particle:nth-child(7) {
            left: 70%;
            top: 70%;
            --speed: 14s;
        }

        .particle:nth-child(8) {
            left: 80%;
            top: 35%;
            --speed: 10s;
        }

        .particle:nth-child(9) {
            left: 89%;
            top: 67%;
            --speed: 17s;
        }

        .particle:nth-child(10) {
            left: 95%;
            top: 22%;
            --speed: 12s;
        }

        @keyframes particleFloat {

            0% {
                transform:
                    translateY(0)
                    scale(.4);

                opacity: 0;
            }

            25% {
                opacity: .7;
            }

            50% {
                transform:
                    translate(35px, -150px)
                    scale(1.3);

                opacity: 1;
            }

            100% {
                transform:
                    translate(-30px, -320px)
                    scale(.2);

                opacity: 0;
            }
        }


        /* =========================================================
           LIGHT BEAMS
        ========================================================= */

        .beam {
            position: absolute;

            top: -35%;

            width: 2px;
            height: 170%;

            opacity: .16;

            background:
                linear-gradient(
                    to bottom,
                    transparent,
                    var(--cyan),
                    white,
                    var(--violet),
                    transparent
                );

            box-shadow:
                0 0 25px
                rgba(0, 234, 255, .5);

            transform:
                rotate(25deg);

            animation:
                beamMove
                9s
                linear
                infinite;
        }

        .beam.one {
            left: 12%;
        }

        .beam.two {
            left: 78%;

            animation-delay: -4s;
        }

        @keyframes beamMove {

            from {
                transform:
                    translateX(-350px)
                    rotate(25deg);
            }

            to {
                transform:
                    translateX(550px)
                    rotate(25deg);
            }
        }


        /* =========================================================
           VERIFY PORTAL
        ========================================================= */

        .portal {
            position: relative;

            z-index: 20;

            width:
                min(
                    540px,
                    calc(100% - 30px)
                );

            perspective: 1500px;

            animation:
                portalIn
                1s
                cubic-bezier(.16, 1, .3, 1)
                both;
        }

        @keyframes portalIn {

            from {
                opacity: 0;

                transform:
                    translateY(60px)
                    scale(.93)
                    rotateX(10deg);

                filter: blur(10px);
            }

            to {
                opacity: 1;

                transform:
                    translateY(0)
                    scale(1)
                    rotateX(0);

                filter: blur(0);
            }
        }


        /* =========================================================
           GLASS FRAME
        ========================================================= */

        .glass-frame {
            position: relative;

            padding: 1px;

            border-radius: 30px;

            background:
                linear-gradient(
                    130deg,
                    rgba(0, 234, 255, .7),
                    rgba(255, 255, 255, .4),
                    rgba(105, 72, 216, .55),
                    rgba(0, 184, 212, .65)
                );

            background-size: 300% 300%;

            animation:
                frameFlow
                8s
                ease-in-out
                infinite;

            box-shadow:
                0 30px 100px rgba(0, 0, 0, .45),
                0 0 50px rgba(0, 174, 255, .06);
        }

        @keyframes frameFlow {

            0% {
                background-position:
                    0% 50%;
            }

            50% {
                background-position:
                    100% 50%;
            }

            100% {
                background-position:
                    0% 50%;
            }
        }


        /* =========================================================
           VERIFY CARD
        ========================================================= */

        .verify-card {
            position: relative;

            overflow: hidden;

            padding:
                34px 43px 30px;

            border-radius: 29px;

            background:
                linear-gradient(
                    145deg,
                    rgba(255, 255, 255, .95),
                    rgba(239, 246, 255, .88)
                );

            backdrop-filter:
                blur(20px)
                saturate(140%);

            -webkit-backdrop-filter:
                blur(20px)
                saturate(140%);

            border:
                1px solid
                rgba(255, 255, 255, .9);

            color:
                var(--dark);

            box-shadow:
                inset
                0 1px 0
                rgba(255, 255, 255, 1),

                inset
                0 -1px 0
                rgba(15, 23, 42, .08),

                0 25px 80px
                rgba(0, 0, 0, .32);

            transition:
                transform .25s ease;
        }


        /* =========================================================
           CARD LIGHT REFLECTION
        ========================================================= */

        .verify-card::before {
            content: "";

            position: absolute;

            inset: 0;

            pointer-events: none;

            background:
                radial-gradient(
                    circle at 15% 5%,
                    rgba(0, 184, 212, .12),
                    transparent 30%
                ),

                linear-gradient(
                    120deg,
                    rgba(255, 255, 255, .7),
                    transparent 30%,
                    transparent 70%,
                    rgba(0, 184, 212, .04)
                );
        }


        /* =========================================================
           CARD SHINE
        ========================================================= */

        .verify-card::after {
            content: "";

            position: absolute;

            top: -120%;
            left: -70%;

            width: 45%;
            height: 300%;

            pointer-events: none;

            background:
                linear-gradient(
                    90deg,
                    transparent,
                    rgba(255, 255, 255, .65),
                    transparent
                );

            transform:
                rotate(20deg);

            animation:
                cardShine
                8s
                ease-in-out
                infinite;
        }

        @keyframes cardShine {

            0%,
            25% {
                transform:
                    translateX(-180%)
                    rotate(20deg);
            }

            65%,
            100% {
                transform:
                    translateX(500%)
                    rotate(20deg);
            }
        }


        /* =========================================================
           MOUSE LIGHT
        ========================================================= */

        .mouse-light {
            position: absolute;

            width: 350px;
            height: 350px;

            border-radius: 50%;

            pointer-events: none;

            transform:
                translate(-50%, -50%);

            background:
                radial-gradient(
                    circle,
                    rgba(0, 184, 212, .12),
                    transparent 68%
                );

            filter: blur(12px);

            left: 50%;
            top: 50%;

            opacity: .65;
        }


        /* =========================================================
           CORNER MARKERS
        ========================================================= */

        .marker {
            position: absolute;

            width: 27px;
            height: 27px;

            z-index: 8;

            border-color:
                rgba(11, 23, 42, .65);

            pointer-events: none;
        }

        .marker.tl {
            top: 18px;
            left: 18px;

            border-top: 1px solid;
            border-left: 1px solid;
        }

        .marker.tr {
            top: 18px;
            right: 18px;

            border-top: 1px solid;
            border-right: 1px solid;
        }

        .marker.bl {
            bottom: 18px;
            left: 18px;

            border-bottom: 1px solid;
            border-left: 1px solid;
        }

        .marker.br {
            bottom: 18px;
            right: 18px;

            border-bottom: 1px solid;
            border-right: 1px solid;
        }


        /* =========================================================
           LOGO
        ========================================================= */

        .logo-area {
            position: relative;

            width: 170px;
            height: 110px;

            margin:
                0 auto 8px;

            display: flex;

            align-items: center;
            justify-content: center;
        }

        .logo {
            position: relative;

            z-index: 5;

            width: 235px;
            height: 105px;

            object-fit: contain;

            filter:
                drop-shadow(
                    0 8px 12px
                    rgba(15, 23, 42, .15)
                )

                drop-shadow(
                    0 0 14px
                    rgba(0, 184, 212, .18)
                );

            animation:
                logoFloat
                4s
                ease-in-out
                infinite;
        }

        @keyframes logoFloat {

            0%,
            100% {
                transform:
                    translateY(0);
            }

            50% {
                transform:
                    translateY(-6px);
            }
        }


        /* =========================================================
           LOGO HALO
        ========================================================= */

        .logo-halo {
            position: absolute;

            width: 94px;
            height: 94px;

            border-radius: 50%;

            border:
                1px solid
                rgba(0, 127, 149, .25);

            box-shadow:
                0 0 20px
                rgba(0, 127, 149, .08),

                inset
                0 0 20px
                rgba(0, 127, 149, .05);

            animation:
                haloRotate
                10s
                linear
                infinite;
        }

        .logo-halo::before {
            content: "";

            position: absolute;

            width: 7px;
            height: 7px;

            top: -4px;
            left: 50%;

            border-radius: 50%;

            background:
                var(--cyan);

            box-shadow:
                0 0 10px
                var(--cyan),

                0 0 20px
                var(--cyan);
        }

        @keyframes haloRotate {

            to {
                transform:
                    rotate(360deg);
            }
        }


        /* =========================================================
           BRAND
        ========================================================= */

        .brand {
            position: relative;

            z-index: 5;

            text-align: center;
        }

        .brand-title {
            margin:
                4px 0 5px;

            font-size: 21px;

            font-weight: 1000;

            letter-spacing: 3px;

            color:
                var(--navy);

            text-shadow:
                0 1px 0
                rgba(255, 255, 255, .9),

                0 3px 8px
                rgba(15, 23, 42, .08);
        }

        .brand-subtitle {
            color:
                var(--slate);

            font-size: 9px;

            font-weight: 800;

            letter-spacing: 2px;
        }


        /* =========================================================
           STATUS
        ========================================================= */

        .status {
            position: relative;

            z-index: 10;

            display: flex;

            align-items: center;
            justify-content: center;

            gap: 8px;

            width: fit-content;

            margin:
                22px auto 25px;

            padding:
                9px 18px;

            border-radius: 999px;

            background:
                rgba(15, 23, 42, .075);

            border:
                1px solid
                rgba(15, 23, 42, .14);

            box-shadow:
                inset
                0 1px 0
                rgba(255, 255, 255, .9),

                0 7px 22px
                rgba(15, 23, 42, .10);

            font-size: 9px;

            letter-spacing: 1.4px;
        }

        .status-dot {
            width: 7px;
            height: 7px;

            flex-shrink: 0;

            border-radius: 50%;

            background:
                var(--green-light);

            box-shadow:
                0 0 8px
                var(--green),

                0 0 16px
                rgba(0, 201, 135, .45);

            animation:
                statusPulse
                1.5s
                ease-in-out
                infinite;
        }

        @keyframes statusPulse {

            0%,
            100% {
                transform:
                    scale(.75);

                opacity: .6;
            }

            50% {
                transform:
                    scale(1.35);

                opacity: 1;
            }
        }

        .status-text {
            color:
                var(--navy-2);

            font-weight: 1000;

            text-shadow:
                0 1px 1px
                rgba(255, 255, 255, .8);
        }

        .status-online {
            color:
                var(--green);

            font-weight: 1000;

            text-shadow:
                0 1px 2px
                rgba(0, 135, 90, .12);
        }


        /* =========================================================
           AUTHENTICATION HEADER
        ========================================================= */

        .auth-title {
            position: relative;

            z-index: 5;

            display: flex;

            align-items: center;

            gap: 12px;

            margin-bottom:
                21px;
        }

        .auth-line {
            flex: 1;

            height: 1px;

            background:
                linear-gradient(
                    90deg,
                    transparent,
                    rgba(11, 23, 42, .20)
                );
        }

        .auth-line:last-child {
            background:
                linear-gradient(
                    90deg,
                    rgba(11, 23, 42, .20),
                    transparent
                );
        }

        .auth-text {
            white-space: nowrap;

            font-family:
                "Courier New",
                monospace;

            font-size: 9px;

            font-weight: 1000;

            letter-spacing: 1.4px;
        }

        .auth-text .first {
            color:
                var(--navy);
        }

        .auth-text .second {
            margin-left: 5px;

            color:
                var(--cyan);

            text-shadow:
                0 1px 2px
                rgba(0, 127, 149, .15);
        }


        /* =========================================================
           VERIFY MESSAGE
        ========================================================= */

        .verify-message {
            position: relative;

            z-index: 5;

            max-width: 400px;

            margin:
                0 auto 20px;

            color:
                var(--slate);

            font-size: 11px;

            font-weight: 500;

            line-height: 1.8;

            text-align: center;
        }

        .verify-message strong {
            color:
                var(--cyan);

            font-weight: 800;
        }


        /* =========================================================
           ALERT
        ========================================================= */

        .alert {
            position: relative;

            z-index: 5;

            margin-bottom: 18px;

            padding:
                11px 13px;

            border:
                1px solid
                rgba(0, 135, 90, .22);

            border-radius: 10px;

            background:
                rgba(220, 252, 231, .72);

            color:
                #166534;

            font-size: 11px;

            font-weight: 700;

            animation:
                alertIn
                .4s
                ease;
        }

        @keyframes alertIn {

            from {
                opacity: 0;

                transform:
                    translateY(-8px);
            }

            to {
                opacity: 1;

                transform:
                    translateY(0);
            }
        }


        /* =========================================================
           RESEND BUTTON
        ========================================================= */

        .resend-button {
            position: relative;

            z-index: 5;

            width: 100%;
            height: 56px;

            overflow: hidden;

            border:
                1px solid
                rgba(7, 59, 145, .25);

            border-radius: 13px;

            cursor: pointer;

            color: #FFFFFF;

            background:
                linear-gradient(
                    110deg,
                    #073B91,
                    #1264D8,
                    #007F95,
                    #1264D8,
                    #073B91
                );

            background-size: 300% 100%;

            font-size: 10px;

            font-family: inherit;

            font-weight: 1000;

            letter-spacing: 2px;

            box-shadow:
                inset
                0 1px 0
                rgba(255, 255, 255, .28),

                0 10px 30px
                rgba(7, 59, 145, .22);

            animation:
                buttonFlow
                6s
                linear
                infinite;

            transition:
                all .3s ease;
        }

        @keyframes buttonFlow {

            from {
                background-position:
                    0% center;
            }

            to {
                background-position:
                    300% center;
            }
        }

        .resend-button::before {
            content: "";

            position: absolute;

            top: -20%;
            left: -100%;

            width: 65%;
            height: 140%;

            transform:
                rotate(18deg);

            background:
                linear-gradient(
                    90deg,
                    transparent,
                    rgba(255, 255, 255, .35),
                    transparent
                );

            animation:
                buttonShine
                3.5s
                ease-in-out
                infinite;
        }

        @keyframes buttonShine {

            0%,
            25% {
                left: -100%;
            }

            70%,
            100% {
                left: 140%;
            }
        }

        .button-content {
            position: relative;

            z-index: 3;

            display: flex;

            align-items: center;
            justify-content: center;

            gap: 12px;
        }

        .button-arrow {
            color:
                #A7F3FF;

            font-size: 17px;

            transition:
                .3s;
        }

        .resend-button:hover {
            transform:
                translateY(-3px);

            box-shadow:
                inset
                0 0 25px
                rgba(255, 255, 255, .10),

                0 18px 45px
                rgba(7, 59, 145, .30),

                0 0 25px
                rgba(0, 184, 212, .18);
        }

        .resend-button:hover
        .button-arrow {
            transform:
                translateX(7px);
        }

        .resend-button:disabled {
            cursor: wait;

            opacity: .75;

            transform: none;
        }


        /* =========================================================
           SPINNER
        ========================================================= */

        .spinner {
            width: 15px;
            height: 15px;

            border:
                2px solid
                rgba(255, 255, 255, .35);

            border-top-color: #FFFFFF;

            border-radius: 50%;

            animation:
                spinnerRotate
                .7s
                linear
                infinite;
        }

        @keyframes spinnerRotate {

            to {
                transform: rotate(360deg);
            }
        }


        /* =========================================================
           LOG OUT
        ========================================================= */

        .logout-button {
            position: relative;

            z-index: 5;

            display: block;

            width: 100%;

            margin-top: 14px;

            border: none;

            background: none;

            cursor: pointer;

            color:
                var(--slate);

            font-family: inherit;

            font-size: 10px;

            font-weight: 700;

            letter-spacing: 1.2px;

            text-transform: uppercase;

            text-align: center;

            transition:
                color .25s ease;
        }

        .logout-button:hover {
            color:
                var(--blue-dark);
        }


        /* =========================================================
           SECURITY FOOTER
        ========================================================= */

        .security {
            position: relative;

            z-index: 5;

            display: flex;

            align-items: center;
            justify-content: center;

            gap: 7px;

            margin-top: 16px;

            color:
                #64748B;

            font-size: 7px;

            font-weight: 700;

            letter-spacing: 1.2px;

            text-transform: uppercase;
        }

        .security-dot {
            color:
                var(--green);

            text-shadow:
                0 1px 4px
                rgba(0, 135, 90, .20);
        }


        /* =========================================================
           FOOTER
        ========================================================= */

        .footer {
            margin-top: 14px;

            text-align: center;

            color:
                rgba(226, 232, 240, .62);

            font-size: 8px;

            font-weight: 600;

            letter-spacing: 1px;
        }


        /* =========================================================
           MOBILE
        ========================================================= */

        @media (max-width: 600px) {

            body {
                overflow-y: auto;
            }

            .scene {
                min-height: 100svh;

                padding:
                    15px 0;
            }

            .portal {
                width:
                    calc(100% - 18px);
            }

            .verify-card {
                padding:
                    27px 21px 25px;

                border-radius:
                    26px;
            }

            .glass-frame {
                border-radius:
                    27px;
            }

            .logo-area {
                height:
                    88px;
            }

            .logo {
                width:
                    205px;

                height:
                    82px;
            }

            .logo-halo {
                width:
                    78px;

                height:
                    78px;
            }

            .brand-title {
                font-size:
                    16px;

                letter-spacing:
                    2px;
            }

            .brand-subtitle {
                font-size:
                    7px;

                letter-spacing:
                    1px;
            }

            .status {
                font-size:
                    7px;

                padding:
                    8px 12px;
            }

            .auth-text {
                font-size:
                    7px;

                letter-spacing:
                    1px;
            }

            .verify-message {
                font-size:
                    10px;
            }
        }


        /* =========================================================
           REDUCED MOTION
        ========================================================= */

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {

                animation-duration:
                    .01ms !important;

                animation-iteration-count:
                    1 !important;

                transition-duration:
                    .01ms !important;
            }
        }

    </style>
</head>


<body>

<div class="scene">


    <!-- =========================================================
         BACKGROUND
    ========================================================= -->

    <div class="grid"></div>

    <div class="aurora a"></div>
    <div class="aurora b"></div>
    <div class="aurora c"></div>

    <div class="orbit one"></div>
    <div class="orbit two"></div>
    <div class="orbit three"></div>

    <div class="beam one"></div>
    <div class="beam two"></div>


    <!-- PARTICLES -->

    <div class="particles">

        <span class="particle"></span>
        <span class="particle"></span>
        <span class="particle"></span>
        <span class="particle"></span>
        <span class="particle"></span>
        <span class="particle"></span>
        <span class="particle"></span>
        <span class="particle"></span>
        <span class="particle"></span>
        <span class="particle"></span>

    </div>


    <!-- =========================================================
         VERIFY PORTAL
    ========================================================= -->

    <main class="portal">

        <div class="glass-frame">

            <section
                class="verify-card"
                id="verifyCard"
            >


                <!-- CORNER MARKERS -->

                <span class="marker tl"></span>
                <span class="marker tr"></span>
                <span class="marker bl"></span>
                <span class="marker br"></span>


                <!-- MOUSE LIGHT -->

                <div
                    class="mouse-light"
                    id="mouseLight"
                ></div>


                <!-- =================================================
                     LOGO
                ================================================= -->

                <div class="logo-area">

                    <div class="logo-halo"></div>

                    <img
                        src="{{ asset('images/L.png') }}"
                        alt="QueueFlow TMC"
                        class="logo"
                    >

                </div>


                <!-- =================================================
                     BRAND
                ================================================= -->

                <div class="brand">

                    <div class="brand-title">
                        QUEUEFLOW TMC
                    </div>

                    <div class="brand-subtitle">
                        SMART CAMPUS QUEUE MANAGEMENT
                    </div>

                </div>


                <!-- =================================================
                     SYSTEM STATUS
                ================================================= -->

                <div class="status">

                    <span class="status-dot"></span>

                    <span class="status-text">
                        SECURE CORE SYSTEM
                    </span>

                    <span class="status-online">
                        ONLINE
                    </span>

                </div>


                <!-- =================================================
                     AUTHENTICATION TITLE
                ================================================= -->

                <div class="auth-title">

                    <span class="auth-line"></span>

                    <div class="auth-text">

                        <span class="first">
                            ACCOUNT
                        </span>

                        <span class="second">
                            EMAIL VERIFICATION
                        </span>

                    </div>

                    <span class="auth-line"></span>

                </div>


                <!-- =================================================
                     NOTICE
                ================================================= -->

                <p class="verify-message">

                    Thanks for signing up! Before getting started,
                    please confirm your email address by clicking
                    the verification link we sent to
                    <strong>your inbox</strong>. If you did not
                    receive it, use the button below and we will
                    gladly send you another.

                </p>


                <!-- =================================================
                     VERIFICATION LINK SENT
                ================================================= -->

                @if (session('status') == 'verification-link-sent')

                    <div class="alert" role="alert">

                        A new verification link has been sent to
                        the email address you provided during
                        registration.

                    </div>

                @endif


                <!-- =================================================
                     RESEND FORM
                ================================================= -->

                <form
                    method="POST"
                    action="{{ route('verification.send') }}"
                    id="resendForm"
                >

                    @csrf

                    <button
                        type="submit"
                        class="resend-button"
                        id="resendButton"
                    >

                        <span class="button-content">

                            <span id="buttonText">
                                RESEND VERIFICATION EMAIL
                            </span>

                            <span
                                class="button-arrow"
                                id="buttonArrow"
                            >
                                →
                            </span>

                        </span>

                    </button>

                </form>


                <!-- =================================================
                     LOG OUT
                ================================================= -->

                <form
                    method="POST"
                    action="{{ route('logout') }}"
                    id="logoutForm"
                >

                    @csrf

                    <button
                        type="submit"
                        class="logout-button"
                    >
                        Log Out
                    </button>

                </form>


                <!-- =================================================
                     SECURITY
                ================================================= -->

                <div class="security">

                    <span class="security-dot">
                        ●
                    </span>

                    END-TO-END PROTECTED USER SESSION

                </div>

            </section>

        </div>


        <!-- =========================================================
             FOOTER
        ========================================================= -->

        <div class="footer">

            © {{ date('Y') }}

            QueueFlow TMC

            •

            TMC College

        </div>

    </main>

</div>


<script>

    /* =========================================================
       CARD MOUSE LIGHT + 3D EFFECT
    ========================================================= */

    const card =
        document.querySelector('.verify-card');

    const mouseLight =
        document.getElementById('mouseLight');


    if (
        card &&
        mouseLight &&
        window.matchMedia(
            '(pointer: fine)'
        ).matches
    ) {

        document.addEventListener(
            'mousemove',
            function (event) {

                const rect =
                    card.getBoundingClientRect();

                const x =
                    event.clientX -
                    rect.left;

                const y =
                    event.clientY -
                    rect.top;


                mouseLight.style.left =
                    x + 'px';

                mouseLight.style.top =
                    y + 'px';


                const centerX =
                    window.innerWidth / 2;

                const centerY =
                    window.innerHeight / 2;


                const rotateY =
                    ((event.clientX - centerX)
                    / centerX) * 1.1;

                const rotateX =
                    ((event.clientY - centerY)
                    / centerY) * -1.1;


                card.style.transform =
                    `
                    rotateX(${rotateX}deg)
                    rotateY(${rotateY}deg)
                    `;
            }
        );


        document.addEventListener(
            'mouseleave',
            function () {

                card.style.transform =
                    '';

            }
        );

    }


    /* =========================================================
       MAGNETIC RESEND BUTTON
    ========================================================= */

    const resendButton =
        document.getElementById('resendButton');


    if (
        resendButton &&
        window.matchMedia(
            '(pointer: fine)'
        ).matches
    ) {

        resendButton.addEventListener(
            'mousemove',
            function (event) {

                const rect =
                    resendButton.getBoundingClientRect();


                const x =
                    event.clientX -
                    rect.left -
                    rect.width / 2;

                const y =
                    event.clientY -
                    rect.top -
                    rect.height / 2;


                resendButton.style.transform =
                    `
                    translate(
                        ${x * .025}px,
                        ${y * .025}px
                    )
                    translateY(-3px)
                    `;
            }
        );


        resendButton.addEventListener(
            'mouseleave',
            function () {

                resendButton.style.transform =
                    '';

            }
        );

    }


    /* =========================================================
       RESEND SUBMIT ANIMATION
    ========================================================= */

    const resendForm =
        document.getElementById('resendForm');

    const buttonText =
        document.getElementById('buttonText');

    const buttonArrow =
        document.getElementById('buttonArrow');


    if (resendForm) {

        resendForm.addEventListener(
            'submit',
            function () {

                if (buttonText) {

                    buttonText.textContent =
                        'SENDING...';

                }


                if (buttonArrow) {

                    const spinner =
                        document.createElement('span');

                    spinner.className =
                        'spinner';

                    buttonArrow.parentElement
                        .replaceChild(
                            spinner,
                            buttonArrow
                        );

                }


                if (resendButton) {

                    resendButton.disabled =
                        true;

                }

            }
        );

    }

</script>

</body>
</html>