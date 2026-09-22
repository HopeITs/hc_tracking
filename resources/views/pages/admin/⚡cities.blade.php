<?php

use App\Models\HrCity;
use App\Models\Project;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.city_directory')] class extends Component {
    use WithPagination;

    private const PerPage = 12;

    /**
     * Each governorate's real geometric centroid on the coverage map
     * (see {@see self::MapOutlinePath}), in that map's own 670.92×590.26
     * coordinate space — computed from the governorate boundary data itself
     * (area centroid of each region's largest ring), not eyeballed. Keyed by
     * the untranslated `name_en` column, since {@see HrCity::name} switches
     * to Arabic under the Arabic locale. "Syria" (nationwide, tied to no
     * single governorate) is deliberately absent; any HR city with no entry
     * here — that one included — falls back to the map's "not shown" tray
     * instead of guessing a position. "Homs Rural" is not a real governorate
     * (an HR-specific split of Homs) and is offset east of Homs's own
     * centroid, into the desert extent of the governorate it belongs to.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private const MapPositions = [
        'Latakia' => [41.1, 206.0],
        'Idleb' => [106.1, 181.1],
        'Aleppo' => [201.2, 130.1],
        'Ar-raqqa' => [335.0, 154.5],
        'Al-Hasakah' => [524.4, 97.9],
        'Deir Az-zor' => [466.6, 258.3],
        'Tartus' => [46.2, 278.4],
        'Hama' => [158.5, 247.6],
        'Homs' => [273.9, 336.1],
        'Homs Rural' => [328.9, 361.1],
        'Rif Dimashq' => [175.8, 451.3],
        'Damascus' => [66.0, 448.6],
        'Qounaitra' => [17.9, 504.3],
        'Daraa' => [60.6, 526.4],
        'Suwaida' => [122.4, 539.0],
    ];

    /**
     * Syria's 14 governorates, each a real administrative boundary (not a
     * schematic shape) — simplified (Douglas-Peucker, ε≈0.8 map units) from
     * "Blank Syria Governate.svg" by Wikimedia Commons contributor Yauk111
     * (CC BY 4.0: commons.wikimedia.org/wiki/File:Blank_Syria_Governate.svg),
     * itself built on the open geoBoundaries-SYR-ADM1 dataset
     * (geoboundaries.org, CC BY 4.0) — credit required for both. Every
     * governorate fills with `currentColor` and strokes with the canvas's
     * own background colour, so the boundary between two governorates reads
     * as a thin gap rather than a drawn line.
     */
    public const MapOutlinePath = <<<'SVG'
        <g transform="matrix(0.84004621,0,0,1,-0.55729438,-0.6634094)">
        <path d="M71.5,446.6L83.9,442.7L88.5,444.4L87,448.1L87.8,448.6L83.6,453.4L84.4,454.2L83.1,455.6L81.7,454.9L79.8,456L79.4,453.9L77.9,454.8L75.2,453.4L70.9,454.7L71.1,449.2L70,449.4L69.7,448.1L71.4,446.6Z" />
        <path d="M358.1,65.7L357,65.9L358.1,68.5L357.2,70.1L360.5,72.1L361.5,75.8L355.5,77.9L351.1,76.6L352.4,84.2L357,84.5L356.3,80.6L360.4,79.4L361,81.3L365.3,79.7L367.1,80.9L365.1,81.3L361.8,88.6L362.8,92.4L359.2,97.7L359.6,99.6L362,100.3L359.2,103.9L360.5,106.9L357.1,109.4L358.5,119.6L356.2,120.7L356.6,123.2L349.9,126.1L349.5,122.6L346.7,122.3L344.5,127.4L347.9,129.4L344.3,132.2L342.6,132.4L341.5,128.4L332.2,129.7L331.4,130.2L334.3,134.8L333.8,138.2L330.9,138.5L327.6,137.4L327.4,133.5L321.4,131.2L321.9,127.7L323.7,125.4L321,123.1L315.6,124.2L306.5,123.2L301.4,126.2L298.1,126.6L298,130.2L295.8,131.9L295,135.6L292.4,136.2L290.9,140.3L294,144.8L292.5,146.2L289.3,145.1L289.4,146.7L294.6,150.4L293.5,151.7L294.3,154.4L300,157.1L301.5,162.2L305.9,163.3L298.2,170.9L295.8,176.1L302.5,175.7L299.3,180.9L302.2,181.6L300.8,188L305,191L304.9,197.4L306.6,202.8L312.1,204.6L313.4,203.7L313.7,205.8L317.9,207.4L317.5,212L311,222L319.5,227.7L308.8,229.4L278.1,223.4L259.6,222.1L257,217.7L250.6,217.7L250.7,219.8L235,220L223.1,218.6L221.5,213.2L213.5,208.8L209.5,211.1L209.8,214.4L207.7,213.6L205.1,214.6L201.3,212.6L202.6,210.5L199.5,209.9L197.7,211.5L195.1,211.8L191.9,208.2L190.5,208.5L189.7,210.2L186.9,208.6L184.4,209.4L182.8,205.6L177.4,204.6L181.5,199.4L179.5,198.4L180.1,195.3L176.1,194.2L178.5,190.9L178.1,187.1L176.5,186.3L178.5,185L177,184L180.5,182.2L180.5,180.5L177.9,179.8L178.5,176.6L175.1,177.8L172.1,175.3L168.3,174.9L167.5,177.9L165.6,177.6L162.8,179.4L163.2,175.8L159.6,174.1L159.5,170.8L156.8,168.8L157.4,166.9L153.1,166.8L151.2,164L149.9,164.2L148.7,158.6L150.4,151.4L146.1,151.7L144.5,148.6L137.3,148.6L133.9,146.5L135.6,143.6L132.4,141.9L130.1,138.7L136.1,137L137.6,134.6L136.7,133.9L138.5,132L144.1,130.9L145.5,128.4L143.4,127L143.4,123.2L140,123.3L139.2,120.8L136.7,120.6L134.3,116.6L132,116.6L132.5,117.8L130,118.6L125.3,118.7L123.1,116.9L118.1,117.6L120.5,114.7L119.9,110.4L115.9,109.6L111.7,97.9L116.2,93.1L117.7,88.2L115.8,82.2L116.2,79.8L121.6,73.4L120,68.4L126.4,60.9L125.3,57.9L126.1,56.8L127.3,58.1L141.3,62.4L152.9,64.8L157.8,64.4L163,66.7L171,71.1L167.7,78.6L175.1,83L176.6,82.6L178.7,78.8L181.6,78.3L186.2,79.4L195.7,78.1L220.6,81.2L241.6,70.3L250,68.2L257.6,68.2L279.4,60L287,58.8L290.5,55.3L305.1,49.6L312,48.8L329.2,50.7L335.2,52.5L348.6,57.6L357.5,65.3Z" />
        <path d="M414.8,447.4L375.6,465.9L219.1,549.6L198.2,521L197,510.6L196,510.1L179.5,507.8L168.7,504.3L150.8,503.4L130.4,487.3L114,488.5L111.4,479.4L107.2,475.1L99.5,478.5L92.9,484.4L93.1,487.5L89.9,488.4L74.3,477.8L73.2,478.3L71.1,485.3L68.1,484.3L63.6,487.4L56.5,486.9L54.8,491L45.3,491.3L45.8,486.6L41.8,486.1L42.2,479.5L37.1,478.6L33,480.1L30.6,476.9L32.7,474.7L24.8,467.8L26,463.2L40.5,454.3L40.6,450.6L39.2,449.5L40,448.5L43.5,446.9L49.7,446.4L53.9,442.4L49.1,437.7L46.9,437.6L44,435L41.5,435.5L38.4,433.8L42.8,430L43.1,426.6L45.6,424.8L48.3,419.4L50.1,419.8L54.6,413.4L59.1,413.2L65.1,409.4L66.9,411.8L70.4,412.4L74.5,411.5L75.6,409.4L84.8,412.7L90.7,413.4L92.5,412L92.5,410.2L89.7,407.3L84.8,406.8L83.4,404.8L79.9,403.6L85.2,395.7L91.9,391L92.6,388.8L94.4,388.9L93.9,387.3L95.6,387.3L96.7,385.7L103.2,387.6L104.1,386.4L105,389L105.6,386.4L107.6,386.8L106,385.6L107.2,380.6L113.5,376L113.4,374.2L116.6,370.4L120.2,368.5L117.3,365.9L124.6,364.6L128.4,365.7L140.1,363.7L140,365.5L143.1,366L143.8,367.9L147.4,370L152.7,370.3L157.7,376.6L165,381.6L158.6,386.3L164,389.7L166.6,389.7L165.4,399.2L199.2,400.2L211.1,397.3L214.2,397.8L221.4,394.4L232.7,393.8L236.2,395.3L235.5,399.6L236.4,401.2L241.6,402.8L392.2,420.5L413.5,445.7ZM71.4,446.6L69.7,448.1L70,449.4L71.1,449.2L70.9,454.7L75.2,453.5L77.9,454.8L79.4,454L79.8,456L81.7,454.9L83.1,455.7L84.4,454.2L83.6,453.4L87.8,448.6L87,448.1L88.5,444.4L83.9,442.7L71.5,446.5Z" />
        <path d="M414.8,447.4L392.2,420.5L241.6,402.8L236.4,401.2L235.5,399.6L236.2,395.3L232.7,393.8L221.4,394.4L214.2,397.8L211.1,397.3L199.2,400.2L165.4,399.2L166.6,389.7L164,389.7L158.6,386.3L165,381.6L157.7,376.6L152.6,370.3L147.4,370L143.8,367.9L143.1,366L140,365.5L140.1,363.7L128.4,365.7L124.6,364.6L115.9,365.5L114.3,359.6L117.4,356.3L112.9,355L109,348.3L111.7,345.5L111.6,342.5L101.1,337.5L99.1,334.9L99.3,332.4L95.6,332L89.9,333.4L85.7,332.7L85.7,330.5L95.6,326.2L93.9,323.2L94.4,320.6L100.1,322.6L101.1,317.3L96.8,319.6L91.7,317.7L87.8,314.4L87,312.1L84.1,311.5L80.6,317.9L75.3,317.3L73.2,318.5L68.8,317L66.8,318.1L60.3,317L61.5,310.9L63.9,309.7L63.6,305.6L66.5,302.8L78.2,297L80.1,293.5L85.2,289.2L83.1,288.7L80.5,290.4L78,289.2L79.9,284.4L81.6,284L84.8,286.7L84.4,287.8L85.5,287.3L86.9,291.2L89.6,289.1L91.7,291.4L101.5,288.2L100.8,283.7L103.9,283.2L103.7,281.5L106.2,279.9L109.3,280.3L107.5,283.4L110.2,283.3L112.1,287L114,286L114,283.8L116.9,282.9L118.2,284.1L117.5,285.7L123.6,285.6L127.6,282L131.4,280.7L133.1,281.1L133.3,282.4L134.4,281.3L136,282.1L137.4,280.3L140.4,281L146.7,277.8L153.3,276.9L159.4,282.8L160.9,282.9L160.8,288.1L163.8,290.4L166.1,290.2L175,285L181.8,283.9L182.9,285.1L186.3,281.7L190.5,282.1L192,284.7L194.5,283.8L197.7,284.4L198.6,281.8L200.6,281.4L200.2,279.7L205.9,277L219.9,277.5L228.5,281.8L240,277.8L245.5,270.8L248.3,263.6L257.2,262.1L259.5,262L268.8,267.3L271,269.7L272.5,275.7L281.1,280.5L289,288.2L292.8,285.2L298,276.2L301.2,273.7L321,267L323.3,264.9L320.2,259.8L322.1,256.1L329.1,251.9L333.4,245.6L335.2,239.7L334.4,227.6L350,227.3L384.9,230.6L421.9,236.4L423.6,237.5L424.1,240.3L435.1,244.7L449.1,255.2L549.1,384.2L419.5,445.2Z" />
        <path d="M187.8,210L190.2,210L190.5,208.5L192,208.3L193.9,211.5L197.7,211.6L199.8,209.9L202.6,210.5L201.3,212.6L203,214L209.8,214.4L209.5,211.1L213.5,208.8L221.5,213.2L223.1,218.6L235,220L250.7,219.8L250.6,217.7L257,217.7L259.6,222.1L278.1,223.4L302.9,228.9L334.4,227.6L335.2,239.7L332.4,247.5L329.1,251.9L321.1,257.8L320.2,259.8L321,262.4L323.4,264.3L322.4,265.9L301.2,273.7L298,276.2L292.8,285.2L289,288.2L281.1,280.5L272.5,275.7L271,269.7L268.8,267.3L259.5,262L248.3,263.6L245.5,270.8L240,277.8L228.5,281.8L219.9,277.5L205.9,277L200.2,279.7L200.6,281.4L198.6,281.8L197.7,284.4L194.5,283.7L192,284.7L190.5,282.1L186.3,281.7L182.9,285.1L181.8,283.9L175,285L163.8,290.4L160.8,288.1L160.9,282.9L159.4,282.8L153.3,276.9L146.6,277.8L140.4,281L137.4,280.3L136,282.1L134.4,281.3L133.3,282.4L133.1,281.1L131.4,280.7L127.6,282L123.6,285.6L117.5,285.7L118.2,284.1L116.9,282.9L114,283.8L114,286L112.1,287L110.2,283.3L107.5,283.3L109.3,280.3L106.2,279.9L103.7,281.5L103.9,283.2L100.8,283.7L101.5,288.2L91.7,291.4L89.6,289.1L86.9,291.2L85.5,287.3L84.3,287.7L84.8,286.7L81.8,285.2L82.1,284.2L77.9,283.9L78.2,280.4L74.6,277L68.6,275.9L62.7,277.6L65.3,273.7L70.1,271.5L75,271L76.4,266.5L75.7,265.4L77.7,264.5L78.6,261.4L76.6,249.3L75.3,245.6L73.2,244.4L74.5,238.1L73.2,231.7L74.1,230.1L70.7,224.9L72.7,219.3L72.5,207.4L73.6,205.1L72,202.5L72.7,197.9L70.7,195.7L71.3,194L72.3,195.2L74.7,195.1L74.9,193.5L75.5,195.1L75.9,190L77,189.8L73.5,185.7L83.2,186.4L84.6,184.5L87.3,186.8L87.3,189.5L92.9,189.1L94.9,192.9L94.3,194.6L92,195L93.9,196L93.1,202.2L94.5,203.5L92.9,206L92.9,210.7L98.9,211.3L100.5,214.6L100.3,218.1L102.4,218.3L102.5,219.7L106.2,220.9L108.4,223.1L107.6,226.2L115.5,224.7L115.7,226.8L121.9,227.7L121.8,229.8L126.1,226.6L134.4,226.8L140.3,225.5L148,225.8L149.9,226.9L150,229.3L152,229.8L158.2,229.5L164.4,223.4L166.4,225.7L173.8,225.9L176.7,223.8L176.6,219.1L181.2,220.3L183.6,214.1L186.6,212.6L187.4,210.4Z" />
        <path d="M73.5,185.7L77,189.8L75.9,190L75.5,195.1L74.9,193.5L74.7,195.1L72.3,195.2L71.3,194L70.7,195.7L72.7,197.9L72,202.5L73.6,205.1L72.6,207.4L72.7,219.3L70.7,224.9L74.1,230.1L73.2,231.7L74.5,238.1L73.2,244.4L66,248.3L52.5,247.8L48.3,247.1L45.4,244.6L43.6,245.5L40.2,242.4L43,244L42.2,242.4L38.1,241.9L37.6,224.4L24.8,214.9L19.8,214.5L20.7,210.7L16.3,207.7L17,205.3L14.4,205.5L18.1,204.6L19.8,202L20.9,202.5L21.7,198.6L19.9,196.2L20.4,195L23.7,193.4L29.2,186.2L28.5,181.9L25,175.7L25.3,174.2L23.5,173.7L29.4,173.8L32.4,172L35.3,166L37.3,164.7L38.9,166.1L47.5,163.5L49.2,165.4L48.7,170.5L62.3,173.5L64.8,177.7L64,181.1L68.7,183L71,185.6L73.1,185.7Z" />
        <path d="M125.4,118.7L130.8,118.5L132.5,117.8L132,116.6L134.3,116.7L136.7,120.6L139.2,120.8L140,123.3L143.4,123.1L143.4,127L145.5,128.4L144.1,130.9L138.5,132L136.7,133.9L137.7,134.6L136.1,137L130.2,139.2L135.6,143.6L134.2,144.7L134.5,147L137.3,148.6L144.5,148.6L146.1,151.7L150.4,151.5L148.7,158.6L149.9,164.2L151.2,164L153.1,166.8L157.4,166.9L156.8,168.8L159.5,170.8L159.6,174.1L163.2,175.8L162.8,179.4L165.6,177.7L167.6,177.9L168.4,174.9L172.2,175.3L175.1,177.8L178.6,176.6L177.9,179.8L180.5,180.5L180.5,182.2L177,184L178.5,185L176.5,186.3L178.1,187.1L178.5,190.9L176.1,194.2L180.1,195.3L179.5,198.4L181.5,199.4L177.4,204.6L182.8,205.6L184.4,209.5L186.9,208.6L188.2,209.5L186.6,212.6L183.6,214.1L181.2,220.3L176.7,219.1L176.7,223.8L173.8,225.9L166.4,225.7L164.4,223.4L158.9,229.2L152,229.8L150,229.3L149.9,226.9L148,225.8L126.1,226.6L121.8,229.8L121.9,227.7L115.7,226.8L115.5,224.7L107.6,226.2L108.4,223.1L106.2,220.9L102.5,219.7L102.4,218.3L100.3,218.1L100.5,214.6L98.9,211.3L92.9,210.7L92.9,206L94.5,203.5L93.1,202.2L93.9,196L92,195L94.3,194.6L94.9,192.9L92.9,189.1L87.3,189.5L87.3,186.8L84.6,184.5L83.2,186.4L71,185.6L68.7,183L64,181.1L64.8,177.7L66.4,178.2L68.1,175.8L68.8,170.1L67.8,168L71.6,162.2L75.3,160.8L82.2,161.5L81.6,157.4L83.9,156L87.1,157.4L91.1,156.4L93.4,146.6L91.4,142.5L92.7,139.7L90.9,136.5L93.7,133.9L93.4,130.2L102,132.7L104.9,128.8L115.3,129.1L119.4,130.9L122,129.4L126,129.4L129.7,126.4L128.1,124.4L129,122L125.3,119.8Z" />
        <path d="M669.8,216.1L660.4,210.9L632.3,209.6L606.6,201.6L595.8,194.9L566.9,172.6L560.9,163.7L548.5,158.3L545.4,153.4L541.5,153.1L529.6,146.3L526.9,140.4L518.7,135L518.2,131.8L511.3,127.6L506,116.9L496,112.6L494.3,112.9L489.5,103.2L466.6,83.7L459.5,73.7L478.4,70.9L485.2,68.8L499.3,67.5L524,57.9L528.2,55L542.4,51.8L569.6,37.6L582.3,35.5L610,24.4L614.9,24.5L618.1,26.1L622.3,24L629.9,24L655.8,27.7L661.5,31.1L667.7,29.3L701.5,28.6L713.2,25.4L725.2,24.8L750.7,19.8L766.1,13.5L770,9.2L776.5,5.4L779.6,1.1L781.3,1.4L779.7,5.9L786.1,6.7L788.4,5.5L789.8,7.8L795.7,11.8L793.1,16.7L795.6,22.1L795.3,27.1L798.5,28.9L799,30.7L769.8,55L746.4,77.5L733.4,86.6L684.2,94.8L670.5,114.5L670.2,125.4L666.2,150L680.7,175.9L682.2,200L676.1,207.9Z" />
        <path d="M549.2,384.2L449.1,255.2L435.1,244.8L428.9,241.6L475.8,184.5L477.6,182.9L482.6,183.5L482.9,157.8L487.6,154.6L488,152.6L492,150.6L496.4,143.6L498.8,142.4L499.4,141L497.8,138.7L499.4,135.3L505.5,128.2L505.5,116.6L511.3,127.6L518.2,131.8L518.7,135L526.9,140.4L529.6,146.3L541.5,153.1L545.4,153.4L548.5,158.3L560.9,163.7L564.4,170.1L577,181.4L581.3,183.4L599.6,197.4L606.5,201.6L632.3,209.6L660.4,210.9L669.8,216.1L668.2,229L663.3,245.1L661.5,258.7L663.8,299.7L652.2,313.9L639.7,338.9L635.5,343.5Z" />
        <path d="M29.7,290.9L29.6,291ZM79.9,284.4L79.8,286.4L77.9,287.8L78.5,289.8L80.5,290.4L83.1,288.7L85.2,289.2L80.1,293.5L78.2,297L71,299.9L63.6,305.6L63.9,309.7L61.5,310.9L61.7,313.8L59.9,317.5L50.5,318.2L47.4,316.1L44.6,315.3L44.2,316.3L39.5,304.9L39.3,300.8L32.4,285L33.3,275.3L35.2,273L33.7,267.4L34.4,261.6L38.3,256.2L38.6,253.7L42.1,250.7L39.1,242.8L43,244L43.6,245.5L45.4,244.6L48.3,247.1L52.5,247.8L66,248.3L71,244.7L74.9,245.2L78.7,259.3L77.8,264.3L75.7,265.4L76.4,266.5L75,271L70.1,271.5L65.3,273.7L62.7,277.6L68.6,275.9L74.6,277L78.2,280.4L77.9,283.8L79.7,284.1Z" />
        <path d="M319.5,227.7L311.1,222L317.5,212.1L317.9,207.4L313.7,205.8L313.4,203.7L312.1,204.6L306.6,202.8L304.9,197.4L305,191L300.8,188L302.2,181.6L299.3,180.9L302.5,175.7L295.8,176.1L298.2,170.9L305.9,163.3L301.6,162.2L300.3,157.5L294.3,154.5L293.5,151.7L294.6,150.4L289.1,145.7L290.3,144.9L292.7,146.2L294,144.8L290.9,140.3L292.4,136.2L295,135.6L295.8,131.9L298,130.2L298.1,126.6L301.4,126.2L306.5,123.2L315.5,124.2L321,123.1L323.7,125.4L321.9,127.7L321.4,131.2L327.4,133.5L327.6,137.4L330.9,138.5L333.8,138.2L334.3,134.8L331.4,130.2L332.1,129.7L341.5,128.4L342.6,132.4L344.3,132.2L347.9,129.4L344.5,127.4L346.7,122.3L349.5,122.6L349.9,126.1L356.6,123.2L356.2,120.7L358.5,119.6L357.1,109.4L360.5,106.9L359.2,103.9L362,100.3L359.6,99.6L359.2,97.7L362.8,92.4L361.8,88.6L365.1,81.3L367.1,80.9L365.3,79.7L361,81.3L360.4,79.4L356.3,80.6L356.9,84.5L352.4,84.2L351.1,76.6L355.5,77.9L361.5,75.8L360.5,72.1L357.2,70.1L358.1,68.5L357,65.9L367.5,72.7L376.6,74.3L402.4,73.6L426.8,78.1L440.7,75.2L459.5,73.7L466.6,83.6L489.5,103.2L494.3,112.9L496,112.6L505.5,116.6L505.5,128.2L499.4,135.3L497.8,138.7L499.4,141L498.8,142.3L496.3,143.6L492,150.6L488,152.6L487.6,154.6L482.9,157.8L482.6,183.5L477.6,182.9L475.8,184.5L428.9,241.6L424.1,240.3L423.4,237.1L420.6,235.9L415.9,236.1L384.9,230.6L350,227.3L334.4,227.6Z" />
        <path d="M45.3,491.3L54.8,491L56.5,486.9L63.6,487.4L68.1,484.3L71.1,485.3L73.6,477.9L89.9,488.4L93.4,487.2L92.8,484.4L96.7,480.7L99.5,478.4L104.3,477.1L106.1,475.1L111.4,479.4L113.9,488.5L108.2,488.9L105.8,490.3L104.4,496.2L107.5,503.2L109.5,503.7L111.8,508.1L97,511.4L90.8,516.8L91.5,519.9L90.4,527.2L92.8,539.8L90.7,540.2L88.7,544.2L101.2,550.3L99.9,553.6L93.6,555.3L95.2,560.9L104.4,560.8L106.9,562.4L107.1,567.8L110.6,573.2L108,576.3L105.4,585.2L93.7,582.2L86.2,575.7L70.7,565.6L67.5,566.8L57.2,566.8L54.8,560L50.6,556.3L50.8,550.8L43.4,548.8L44.5,546.5L40.8,545L39,542.5L32.2,541.2L26,541.7L23.8,540.9L23.1,538.4L16.7,541.6L20.6,536.8L28,531.8L29.1,528.3L34.3,523.9L39.7,521.9L39.4,518.2L41.8,516.9L43.6,518.5L46.2,517.4L44.6,511.6L40.2,505.7L44.9,496.3L45.2,491.6Z" />
        <path d="M114,488.5L130.4,487.3L150.8,503.4L168.7,504.3L179.5,507.8L197,510.6L198.2,521L219.1,549.6L148.7,587.5L144.9,590.6L105.6,585.4L108,576.3L110.6,573.3L107.1,567.8L106.9,562.4L104.4,560.8L95.2,560.9L93.6,555.3L99.9,553.6L101.2,550.3L88.7,544.2L90.7,540.2L92.8,539.8L90.4,527.2L91.5,519.9L90.8,516.9L97,511.4L111.8,508.1L109.5,503.7L107.5,503.2L104.4,496.2L105.8,490.3L113.2,488.6Z" />
        <path d="M24.8,467.8L32.7,474.7L30.6,476.9L33,480.1L37.1,478.6L41.5,478.8L42.5,480.6L41.8,486.1L45.8,486.6L45,495.8L40.2,505.7L44.5,511.6L46.2,517.4L43.6,518.5L41.7,516.9L39.4,518.1L39.7,521.9L34.3,523.9L29.1,528.3L28.5,531.1L25.7,534.1L20.6,536.8L16.7,541.6L12.1,542.8L8.1,547.1L4.6,548L3.5,546.3L6.7,536.3L5.9,532.6L4.6,532.3L4.6,526L1,521.8L2.5,520.5L2.9,508.4L5.7,495.4L7.7,492L7.3,483.5L9.3,481.4L5.4,480.6L2.2,481.5L1.8,478.1L4.4,477L6.3,477.7L12.2,474.2L13.2,471.5L16.6,471.7L24.7,467.9Z" />
        </g>
        SVG;

    /**
     * The governorate the connection lines on the map radiate from.
     */
    private const HeadquartersCity = 'Damascus';

    #[Url(except: '')]
    public string $search = '';

    /**
     * '' (all), 'active' or 'inactive' — filters on HrCity::is_active.
     */
    #[Url(except: '')]
    public string $status = '';

    /**
     * 'name', 'projects' or 'active' — see {@see self::cities()}.
     */
    #[Url(except: 'name')]
    public string $sort = 'name';

    /**
     * 'map' or 'grid' — which body the page renders below the summary strip.
     */
    #[Url(except: 'map')]
    public string $view = 'map';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    /**
     * Cities come from the HR database and are read-only here; every city is
     * listed, active or not, exactly as HR holds it.
     *
     * Sorting by name stays a plain database query. Sorting by delivery
     * signal (project count, active-first) needs {@see self::projectStatsByCity()}
     * for every matching city up front — cities live in a different database
     * connection from projects, so that can never be a single ordered query —
     * which means those two modes fetch every matching city once and paginate
     * the sorted collection in PHP instead of in SQL. HR city lists are small
     * reference tables (dozens, not thousands), so this stays cheap.
     *
     * @return LengthAwarePaginator<int, HrCity>
     */
    #[Computed]
    public function cities(): LengthAwarePaginator
    {
        $search = trim($this->search);

        $query = HrCity::query()
            ->select(['id', 'name_en', 'name_ar', 'is_active'])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name_en', 'like', '%'.$search.'%')
                ->orWhere('name_ar', 'like', '%'.$search.'%')))
            ->when($this->status !== '', fn ($query) => $query->where('is_active', $this->status === 'active'));

        if ($this->sort === 'name') {
            return $query
                ->orderBy(HrCity::nameColumn())
                ->orderBy('id')
                ->paginate(self::PerPage);
        }

        $stats = $this->projectStatsByCity;
        $page = (int) $this->getPage();

        $sorted = $query->get()
            ->sort(fn (HrCity $a, HrCity $b): int => match ($this->sort) {
                'projects' => ($stats->get($b->id)['total'] ?? 0) <=> ($stats->get($a->id)['total'] ?? 0),
                'active' => (int) $b->is_active <=> (int) $a->is_active,
                default => 0,
            } ?: strcmp($a->name, $b->name))
            ->values();

        return new LengthAwarePaginator(
            $sorted->forPage($page, self::PerPage)->values(),
            $sorted->count(),
            self::PerPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /**
     * Delivery signal for every HR city that has at least one project, keyed
     * by city id: how many projects, how many are still open vs closed, how
     * many distinct programs are behind them, and the beneficiaries targeted.
     *
     * Scoped to the whole portfolio rather than just the visible page — the
     * summary strip and the "most projects first" sort both need every city's
     * figures, not only the 12 on screen.
     *
     * @return Collection<int, array{total: int, open: int, closed: int, programs: int, beneficiaries: int}>
     */
    #[Computed]
    public function projectStatsByCity(): Collection
    {
        return Project::query()
            ->select(['city_id', 'program_id', 'status', 'target_beneficiaries'])
            ->get()
            ->groupBy('city_id')
            ->map(function (Collection $projects): array {
                $open = $projects->filter(fn (Project $project): bool => $project->status->isOpen())->count();

                return [
                    'total' => $projects->count(),
                    'open' => $open,
                    'closed' => $projects->count() - $open,
                    'programs' => $projects->pluck('program_id')->unique()->count(),
                    'beneficiaries' => (int) $projects->sum('target_beneficiaries'),
                ];
            });
    }

    /**
     * Portfolio-wide figures for the summary strip — independent of the
     * current search, filter or page.
     *
     * @return array{totalCities: int, activeCities: int, coveredCities: int, openProjects: int, beneficiaries: int}
     */
    #[Computed]
    public function summary(): array
    {
        $stats = $this->projectStatsByCity;

        return [
            'totalCities' => HrCity::count(),
            'activeCities' => HrCity::where('is_active', true)->count(),
            'coveredCities' => $stats->count(),
            'openProjects' => (int) $stats->sum('open'),
            'beneficiaries' => (int) $stats->sum('beneficiaries'),
        ];
    }

    /**
     * Every HR city, unpaginated — the coverage map plots the whole
     * portfolio at once rather than one page of results, since removing
     * cities from view when the list scrolls would break the geography.
     *
     * @return Collection<int, HrCity>
     */
    #[Computed]
    public function allCities(): Collection
    {
        return HrCity::query()
            ->select(['id', 'name_en', 'name_ar', 'is_active'])
            ->orderBy(HrCity::nameColumn())
            ->orderBy('id')
            ->get();
    }

    /**
     * Every city's full delivery picture, ready to hand to the map's Alpine
     * component as one JSON blob — every label pre-translated and every
     * count pre-pluralised server-side, so the client only ever swaps which
     * city's already-correct strings are on screen.
     *
     * Cities the search or status filter would exclude stay on the map
     * (removing one would leave a hole in the geography) but are flagged
     * `matches: false` so the template can dim them instead.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function mapCities(): array
    {
        $stats = $this->projectStatsByCity;
        $search = mb_strtolower(trim($this->search));

        return $this->allCities
            ->map(function (HrCity $city) use ($stats, $search): array {
                $name = trim($city->name_en);
                $position = self::MapPositions[$name] ?? null;
                $cityStats = $stats->get($city->id, ['total' => 0, 'open' => 0, 'closed' => 0, 'programs' => 0, 'beneficiaries' => 0]);

                $matchesSearch = $search === ''
                    || str_contains(mb_strtolower($city->name_en), $search)
                    || str_contains(mb_strtolower($city->name_ar), $search);
                $matchesStatus = $this->status === '' || ($this->status === 'active') === $city->is_active;

                return [
                    'id' => $city->id,
                    'name' => $city->name,
                    'secondaryName' => $city->secondary_name,
                    'isActive' => $city->is_active,
                    'statusLabel' => $city->is_active ? __('common.active') : __('cities.inactive'),
                    'nationwide' => $name === 'Syria',
                    'x' => $position[0] ?? null,
                    'y' => $position[1] ?? null,
                    'isHq' => $name === self::HeadquartersCity,
                    'total' => $cityStats['total'],
                    'open' => $cityStats['open'],
                    'closed' => $cityStats['closed'],
                    'openPct' => $cityStats['total'] > 0 ? (int) round(($cityStats['open'] / $cityStats['total']) * 100) : 0,
                    'projectsLabel' => trans_choice('common.count_project', $cityStats['total'], ['count' => $cityStats['total']]),
                    'programsLabel' => $cityStats['total'] > 0
                        ? trans_choice('cities.count_program', $cityStats['programs'], ['count' => $cityStats['programs']])
                        : null,
                    'beneficiariesLine' => $cityStats['beneficiaries'] > 0
                        ? Number::format($cityStats['beneficiaries']).' '.__('cities.beneficiaries_reached')
                        : null,
                    'noProjectsLabel' => __('cities.no_projects_here_yet'),
                    'href' => route('projects.index', ['city' => $city->id]),
                    'matches' => $matchesSearch && $matchesStatus,
                ];
            })
            ->values()
            ->all();
    }
};
?>

@php
    $secondaryButtonClasses = 'border-secondary-light! bg-white! text-secondary-dark! hover:bg-secondary-soft! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white! dark:hover:bg-secondary-medium!';
    $summary = $this->summary;
    $stats = $this->projectStatsByCity;

    $figures = [
        ['key' => 'covered', 'label' => __('cities.cities_covered'), 'value' => $summary['coveredCities'], 'suffix' => '', 'sub' => __('cities.cities_covered_hint', ['total' => $summary['totalCities']]), 'tone' => 'text-funding'],
        ['key' => 'active', 'label' => __('cities.active_cities'), 'value' => $summary['activeCities'], 'suffix' => '', 'sub' => __('cities.cities_covered_hint', ['total' => $summary['totalCities']]), 'tone' => 'text-status-good'],
        ['key' => 'open', 'label' => __('cities.open_projects'), 'value' => $summary['openProjects'], 'suffix' => '', 'sub' => __('cities.open_projects_hint'), 'tone' => ''],
        ['key' => 'reached', 'label' => __('cities.beneficiaries_reached'), 'value' => $summary['beneficiaries'], 'suffix' => '', 'sub' => __('cities.beneficiaries_reached_hint'), 'tone' => 'text-primary'],
    ];

    // A calm rotation of already-brand-safe soft tones so the grid reads as
    // lively rather than sixteen identical grey tiles — never the primary
    // red, which the rest of the app reserves for late/critical signal.
    $avatarTones = [
        'bg-funding-soft text-funding',
        'bg-status-active-soft text-status-active',
        'bg-status-good-soft text-status-good',
        'bg-status-warn-soft text-status-warn',
        'bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white',
    ];

    $sortOptions = [
        'name' => __('cities.sort_name'),
        'projects' => __('cities.sort_most_projects'),
        'active' => __('cities.sort_active_first'),
    ];

    $statusOptions = [
        ['key' => '', 'label' => __('cities.all_cities')],
        ['key' => 'active', 'label' => __('common.active')],
        ['key' => 'inactive', 'label' => __('cities.inactive')],
    ];

    $mapCities = collect($this->mapCities);
    $mappedCities = $mapCities->filter(fn (array $city): bool => $city['x'] !== null)->values();
    $unmappedCities = $mapCities->reject(fn (array $city): bool => $city['x'] !== null)->values();
    $hqCity = $mappedCities->firstWhere('isHq', true);
    $defaultSelectedCityId = optional($mappedCities->sortByDesc('total')->first())['id'] ?? optional($mappedCities->first())['id'] ?? null;
    $matchingMapCount = $mapCities->where('matches', true)->count();
    $mapFiltersActive = $search !== '' || $status !== '';
@endphp

<section
    class="admin-directory @container/directory flex min-h-full w-full flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white"
    aria-labelledby="city-directory-heading"
>
    <x-ui.page-header heading-id="city-directory-heading" :title="__('common.cities')" :count="$this->cities->total()">
        <x-slot:filters>
            <x-ui.input
                type="search"
                icon="magnifying-glass"
                :label="__('cities.search_cities')"
                :placeholder="__('cities.city_name')"
                wire:model.live.debounce.300ms="search"
                clearable
            />

            <x-ui.header-filter-select :label="__('cities.sort_by')" wire:model.live="sort" :value="$sort">
                @foreach ($sortOptions as $optionValue => $optionLabel)
                    <flux:select.option :value="$optionValue">{{ $optionLabel }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.view-toggle
                    wire-model="view"
                    :active="$view"
                    :options="[
                        ['key' => 'map', 'label' => __('cities.view_map'), 'icon' => 'map'],
                        ['key' => 'grid', 'label' => __('cities.view_grid'), 'icon' => 'squares-2x2'],
                    ]"
                    :label="__('cities.view_as')"
                />

                <x-ui.view-toggle
                    wire-model="status"
                    :active="$status"
                    :options="$statusOptions"
                    :label="__('cities.status')"
                />
            </div>
        </x-slot:actions>

        <x-slot:summary>
            {{-- The portfolio's figures, set directly on the page rather than boxed into cards: the numbers are the point (same treatment as reports/⚡index.blade.php). --}}
            <div class="grid grid-cols-2 gap-x-6 gap-y-6 border-y border-secondary-soft py-6 @xl/directory:grid-cols-4 @xl/directory:divide-x @xl/directory:divide-secondary-soft dark:border-secondary-medium @xl/directory:dark:divide-secondary-medium">
                @foreach ($figures as $index => $figure)
                    <div
                        class="logframe-reveal @xl/directory:ps-6 @xl/directory:first:ps-0"
                        style="--logframe-order: {{ $index }}"
                        wire:key="figure-{{ $figure['key'] }}"
                    >
                        <p class="type-label text-secondary-medium dark:text-secondary-soft">{{ $figure['label'] }}</p>
                        <p
                            class="mt-1.5 text-4xl leading-none font-extrabold tabular-nums {{ $figure['tone'] }}"
                            x-data="countUp({{ (int) $figure['value'] }})"
                            x-text="display + '{{ $figure['suffix'] }}'"
                        >{{ $figure['value'] }}{{ $figure['suffix'] }}</p>
                        <p class="mt-1 text-xs text-secondary-medium dark:text-secondary-soft">{{ $figure['sub'] }}</p>
                    </div>
                @endforeach
            </div>
        </x-slot:summary>
    </x-ui.page-header>

    @if ($view === 'map')
        @include('pages.admin._cities-map', [
            'mappedCities' => $mappedCities,
            'unmappedCities' => $unmappedCities,
            'hqCity' => $hqCity,
            'defaultSelectedCityId' => $defaultSelectedCityId,
            'matchingMapCount' => $matchingMapCount,
            'mapFiltersActive' => $mapFiltersActive,
            'avatarTones' => $avatarTones,
            'mapOutlinePath' => $this::MapOutlinePath,
        ])
    @else
    <div class="flex min-h-8 flex-wrap items-center justify-between gap-2">
        <p class="text-xs font-semibold text-secondary-medium dark:text-secondary-soft">
            {{ trans_choice('cities.count_matching_cities', $this->cities->total(), ['count' => $this->cities->total()]) }}
        </p>

        <div class="flex items-center gap-2">
            <span wire:loading.delay.flex wire:target="search,status,sort,clearSearch,gotoPage,previousPage,nextPage" class="hidden items-center gap-1.5 text-xs text-secondary-medium dark:text-secondary-soft">
                <flux:icon.arrow-path class="size-3.5 animate-spin" />
                {{ __('common.updating') }}
            </span>

            @if ($search !== '')
                <flux:button
                    size="sm"
                    variant="ghost"
                    class="text-secondary-medium! hover:bg-secondary-soft! hover:text-secondary-dark! dark:text-secondary-soft! dark:hover:bg-secondary-medium! dark:hover:text-white!"
                    wire:click="clearSearch"
                >
                    {{ __('cities.clear_search') }}
                </flux:button>
            @endif
        </div>
    </div>

    @if ($this->cities->isEmpty())
        <x-ui.empty-state
            icon="map-pin"
            :heading="__('cities.no_matching_cities')"
            :body="__('common.change_the_search_and_try_again')"
            class="rounded-xl border border-secondary-soft dark:border-secondary-medium"
        >
            <x-slot:action>
                <flux:button size="sm" :class="$secondaryButtonClasses" wire:click="clearSearch">
                    {{ __('cities.clear_search') }}
                </flux:button>
            </x-slot:action>
        </x-ui.empty-state>
    @else
        <div class="grid grid-cols-1 gap-4 @sm/directory:grid-cols-2 @4xl/directory:grid-cols-3 @7xl/directory:grid-cols-4">
            @foreach ($this->cities as $index => $city)
                @php
                    $cityStats = $stats->get($city->id, ['total' => 0, 'open' => 0, 'closed' => 0, 'programs' => 0, 'beneficiaries' => 0]);
                    $openPct = $cityStats['total'] > 0 ? ($cityStats['open'] / $cityStats['total']) * 100 : 0;
                    $avatarTone = $avatarTones[$city->id % count($avatarTones)];
                @endphp
                <a
                    href="{{ route('projects.index', ['city' => $city->id]) }}"
                    wire:navigate
                    wire:key="city-{{ $city->id }}"
                    aria-label="{{ __('cities.view_projects_in_name', ['name' => $city->name]) }}"
                    class="logframe-reveal group relative flex flex-col gap-4 overflow-hidden rounded-xl border border-secondary-soft bg-white p-4 shadow-[0_1px_2px_rgba(16,24,40,0.04),0_1px_3px_rgba(16,24,40,0.06)] transition-all hover:-translate-y-0.5 hover:border-secondary-light hover:shadow-[0_2px_4px_rgba(16,24,40,0.06),0_4px_8px_rgba(16,24,40,0.08)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:border-secondary-medium dark:bg-secondary-dark dark:shadow-none dark:hover:shadow-none"
                    style="--logframe-order: {{ min($index, 11) }}"
                >
                    <span
                        aria-hidden="true"
                        class="{{ $city->is_active ? 'bg-status-good' : 'bg-secondary-light dark:bg-secondary-medium' }} absolute inset-x-0 top-0 h-1"
                    ></span>

                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="{{ $avatarTone }} flex size-11 shrink-0 items-center justify-center rounded-xl">
                                <flux:icon.map-pin class="size-5" />
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-base font-bold text-secondary-dark dark:text-white">{{ $city->name }}</p>
                                <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft" dir="auto">{{ $city->secondary_name }}</p>
                            </div>
                        </div>

                        <span @class([
                            'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap',
                            'bg-status-good-soft text-status-good' => $city->is_active,
                            'bg-secondary-soft text-secondary-medium dark:bg-secondary-medium dark:text-secondary-soft' => ! $city->is_active,
                        ])>
                            {{ $city->is_active ? __('common.active') : __('cities.inactive') }}
                        </span>
                    </div>

                    <div class="flex items-end justify-between gap-3">
                        <div class="min-w-0">
                            <p class="type-figure text-secondary-dark dark:text-white">{{ $cityStats['total'] }}</p>
                            <p class="type-meta text-secondary-medium dark:text-secondary-soft">
                                {{ trans_choice('common.count_project', $cityStats['total'], ['count' => $cityStats['total']]) }}
                            </p>
                        </div>

                        @if ($cityStats['total'] > 0)
                            <div class="min-w-0 text-end">
                                <p class="type-meta truncate text-secondary-medium dark:text-secondary-soft">
                                    {{ trans_choice('cities.count_program', $cityStats['programs'], ['count' => $cityStats['programs']]) }}
                                </p>
                                @if ($cityStats['beneficiaries'] > 0)
                                    <p class="type-meta truncate text-secondary-medium dark:text-secondary-soft">
                                        {{ \Illuminate\Support\Number::format($cityStats['beneficiaries']) }} {{ __('cities.beneficiaries_reached') }}
                                    </p>
                                @endif
                            </div>
                        @endif
                    </div>

                    @if ($cityStats['total'] > 0)
                        <div>
                            <div class="flex h-1.5 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium" role="presentation">
                                <div class="h-full bg-status-active" style="width: {{ $openPct }}%"></div>
                                <div class="h-full bg-secondary-light dark:bg-secondary-light/60" style="width: {{ 100 - $openPct }}%"></div>
                            </div>
                            <div class="mt-1 flex items-center justify-between text-[11px] text-secondary-medium dark:text-secondary-soft">
                                <span>{{ $cityStats['open'] }} {{ __('cities.open') }}</span>
                                <span>{{ $cityStats['closed'] }} {{ __('cities.closed') }}</span>
                            </div>
                        </div>
                    @else
                        <p class="type-meta text-secondary-light italic dark:text-secondary-medium">{{ __('cities.no_projects_here_yet') }}</p>
                    @endif

                    <span class="mt-auto flex items-center gap-1 text-xs font-semibold text-secondary-medium transition-colors group-hover:text-primary dark:text-secondary-soft dark:group-hover:text-primary">
                        {{ __('common.projects') }}
                        <flux:icon.chevron-right variant="micro" class="size-3.5 shrink-0 transition-transform duration-200 group-hover:translate-x-0.5 rtl:-scale-x-100 rtl:group-hover:-translate-x-0.5" />
                    </span>
                </a>
            @endforeach
        </div>

        <flux:pagination
            :paginator="$this->cities"
            class="text-secondary-medium! [&_button]:text-secondary-medium! [&_button:hover]:bg-secondary-soft! dark:text-secondary-soft! dark:[&_button]:text-secondary-soft! dark:[&_button:hover]:bg-secondary-medium!"
            scroll-to="#city-directory-heading"
        />
    @endif
    @endif
</section>
