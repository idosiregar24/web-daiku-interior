{{-- Sprint 17 — letterhead, footer and logo watermark of every company letter (offer, invoice). Input: $company (LetterParts::company()). --}}
    <div class="letterhead">
        <table>
            <tr>
                <td class="brand">
                    @if($company['logo'])
                        <img src="{{ $company['logo'] }}" alt="">
                    @else
                        <span class="name">{{ $company['name'] }}</span>
                    @endif
                </td>
                <td class="contact">
                    {{-- Text, then its icon — as on the company's letter. --}}
                    @foreach(['address', 'email', 'phone', 'instagram'] as $field)
                        @if($company[$field])
                            <div class="line">{{ $company[$field] }} <img class="icon" src="{{ $company['icons'][$field] }}" alt=""></div>
                        @endif
                    @endforeach
                </td>
            </tr>
        </table>
        <div class="rule"></div>
    </div>

    <div class="footer">{{ $company['footer'] }}</div>

    @if($company['logo'])
        <img class="watermark" src="{{ $company['logo'] }}" alt="">
    @endif
