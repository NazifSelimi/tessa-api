{{-- Shared email footer with Tessa logo and contact info --}}
<div style="text-align:center; padding:32px 16px 16px; border-top:1px solid #eaeaea; margin-top:32px;">
    {{-- Tessa logo (inline SVG for maximum email client compatibility) --}}
    <div style="margin-bottom:16px;">
        <svg width="268" height="102" viewBox="0 0 268 102" fill="none" style="height:28px; width:auto;">
            <g transform="translate(0,102) scale(0.1,-0.1)" fill="#1a1a1a" stroke="none">
                <path d="M1139 963 c-88 -45 -119 -116 -86 -196 8 -18 53 -81 102 -142 157 -197 211 -298 201 -373 -8 -58 -49 -128 -89 -152 -42 -26 -105 -34 -163 -22 -27 6 -51 9 -53 6 -2 -2 18 -14 45 -26 72 -33 172 -31 240 4 96 50 144 128 144 233 0 51 -6 75 -35 131 -34 69 -53 94 -197 263 -88 104 -111 156 -87 200 8 14 27 32 43 39 40 20 111 -8 138 -54 11 -19 26 -34 34 -34 8 0 14 -8 14 -17 0 -10 3 -28 6 -40 5 -18 1 -25 -20 -33 -17 -6 -26 -17 -26 -32 0 -13 -3 -33 -6 -45 -10 -36 13 -27 45 18 55 76 60 182 9 236 -61 67 -168 81 -259 36z"/>
                <path d="M1605 967 c-71 -34 -97 -66 -103 -125 -6 -63 7 -88 118 -227 99 -124 172 -240 190 -301 10 -34 10 -50 -4 -97 -34 -117 -136 -170 -259 -136 -56 15 -55 5 0 -22 67 -32 181 -33 239 -2 65 34 100 69 128 125 60 121 12 263 -147 435 l-54 57 -47 -14 c-52 -14 -54 -13 -85 43 -19 34 -20 40 -7 59 11 15 12 27 5 45 -5 13 -6 35 -3 49 7 27 58 74 80 74 8 0 19 11 24 25 12 31 51 33 97 5 41 -25 57 -54 40 -72 -15 -16 -18 -240 -2 -235 5 2 23 25 39 51 37 59 47 146 22 194 -43 82 -174 116 -271 69z"/>
                <path d="M83 950 c-26 -11 -31 -45 -7 -54 9 -3 41 -6 73 -6 31 0 62 -5 69 -12 9 -9 12 -95 12 -323 0 -230 4 -316 13 -335 31 -61 125 -60 146 2 7 20 11 146 11 344 l0 314 23 5 c12 3 46 6 76 7 59 3 87 26 63 50 -16 16 -444 23 -479 8z"/>
                <path d="M663 953 c-13 -2 -32 -16 -43 -30 -19 -25 -20 -40 -20 -349 0 -342 3 -363 47 -383 37 -17 337 -15 352 3 26 31 3 43 -90 48 -126 6 -132 13 -137 156 -5 138 -2 142 109 142 62 0 81 3 85 15 13 32 -15 45 -95 45 -47 0 -82 5 -89 12 -15 15 -17 245 -2 268 7 11 34 16 97 20 79 4 88 7 88 25 0 12 -9 22 -25 26 -27 8 -241 9 -277 2z"/>
                <path d="M2253 948 c-10 -13 -32 -74 -184 -511 -63 -183 -79 -241 -70 -250 19 -19 44 5 66 65 32 88 33 88 151 88 68 0 106 -4 111 -12 4 -7 19 -39 33 -72 31 -76 61 -94 115 -72 39 17 60 56 50 95 -10 38 -211 622 -226 654 -13 29 -31 35 -46 15z m11 -429 c16 -47 27 -93 23 -102 -9 -23 -145 -25 -153 -2 -6 15 5 59 38 143 17 43 25 52 42 50 17 -2 27 -21 50 -89z"/>
                <path d="M60 95 c0 -13 56 -15 456 -15 360 0 455 3 452 12 -4 10 -106 14 -456 16 -398 2 -452 0 -452 -13z"/>
                <path d="M1917 103 c-4 -3 -7 -10 -7 -15 0 -4 140 -8 311 -8 244 0 310 3 307 13 -4 9 -75 13 -305 15 -164 1 -302 -1 -306 -5z"/>
            </g>
        </svg>
    </div>

    {{-- Contact info --}}
    <p style="font-size:13px; color:#666; line-height:1.8; margin:0 0 12px 0;">
        <a href="https://tessa.mk" style="color:#1a1a1a; text-decoration:none; font-weight:600;">tessa.mk</a><br>
        <a href="mailto:tessa@tessa.mk" style="color:#666; text-decoration:none;">tessa@tessa.mk</a><br>
        <a href="tel:+38978286003" style="color:#666; text-decoration:none;">078 286 003</a>
        &nbsp;&middot;&nbsp;
        <a href="tel:+38942333003" style="color:#666; text-decoration:none;">042 333 003</a>
    </p>

    {{-- Divider --}}
    <div style="width:40px; height:1px; background:#ddd; margin:16px auto;"></div>

    {{-- Legal line --}}
    <p style="font-size:11px; color:#999; margin:0; line-height:1.6;">
        &copy; {{ date('Y') }} Tessa. All rights reserved.<br>
        You're receiving this email because of your activity on tessa.mk.
    </p>
</div>
