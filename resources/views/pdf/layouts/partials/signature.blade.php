{{-- Sprint 17 — "Hormat kami" + signature of every company letter. Inputs: $company, $signer (LetterParts). --}}
    <table class="sign">
        <tr>
            <td></td>
            <td class="slot">
                Hormat kami,<br>
                @if($signer['signature'])
                    <img class="signature" src="{{ $signer['signature'] }}" alt=""><br>
                @elseif($company['logo'])
                    <img class="signature" src="{{ $company['logo'] }}" alt="" style="height: 40px; margin: 14px 0 8px;"><br>
                @else
                    <br><br><br>
                @endif
                <span class="name">{{ $signer['name'] ?: $company['name'] }}</span>
                @if($signer['title'])<br>{{ $signer['title'] }}@endif
            </td>
        </tr>
    </table>
