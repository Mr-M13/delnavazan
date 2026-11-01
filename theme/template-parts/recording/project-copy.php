<?php
/** Bilingual Project explanation; intentions are separate from approved notices. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
return array(
	'en' => array(
		'language' => 'English',
		'intro' => 'A living tradition. A shared future.',
		'title' => 'An alphabet for the future of Persian music',
		'lead' => 'The Project is Delnavazan’s long-term initiative to help computers understand Persian music through the way people teach and learn it. Our horizon is ten to fifteen years. Our starting point is the knowledge exchanged between an ostad and a student.',
		'status' => 'Project development · lesson recording for the Project is currently disabled on this website. This page explains our direction; it does not activate recording or enrol you in research.',
		'nav' => array(
			'vision' => 'Why this matters',
			'students' => 'For students',
			'teachers' => 'For teachers',
			'example' => 'A learning example',
			'research' => 'Research approach',
			'questions' => 'Questions',
		),
		'sections' => array(
			array(
				'id' => 'vision',
				'eyebrow' => '01 / The purpose',
				'title' => 'Carry the knowledge forward',
				'paragraphs' => array(
					'Persian music carries knowledge that is heard, demonstrated, practised and gradually understood. The character of a phrase, the shaping of an ornament and the meaning of a teacher’s correction cannot always be captured by notation alone.',
					'The radif and the masters who transmitted it, including Mirza Abdollah, remind us that caring for a tradition is work shared across generations. UNESCO describes the radif as a repertoire passed from master to disciple through oral instruction, embodying musical practice and philosophy. <a href="#project-source-1">[1]</a>',
					'We take inspiration from that responsibility. We do not claim to be creating a new radif or replacing the knowledge of musicians. We want to contribute tools that help a living tradition reach future generations.',
					'Our “alphabet” is a metaphor for shared foundations: descriptions, annotated examples, research methods and software that help computers represent Persian music on its own terms. It must leave room for different radifs, teaching traditions and artistic choices.',
				),
			),
			array(
				'id' => 'students',
				'eyebrow' => '02 / For students',
				'title' => 'Your learning deserves care',
				'paragraphs' => array(
					'A lesson includes experimentation, uncertainty and mistakes. Those moments are part of learning. Our intended research use is to understand musical development; it is not a public showcase of your performance.',
					'If recording is introduced, it may include your image, voice, playing, your teacher’s demonstrations and the conversation between you. Research preparation may produce transcripts, musical measurements and annotations linked to relevant passages.',
					'Permission to record a lesson and permission to use eligible material for research or model training are separate decisions. Student research participation is optional. Reading this page, enrolling in lessons or accepting general website terms does not itself opt you into research. <a href="#project-source-6">[6]</a>',
					'Our intended boundary is that private lesson recordings will not be published as advertisements, public teaching videos or entertainment. A research contribution does not guarantee you an AI companion, personalised feedback or another future product.',
					'Before recording begins, the applicable notices must explain what is captured, who can access it, its uses, storage and retention, and how to change your choices or request deletion. The final retention period and detailed withdrawal/deletion rules remain to be settled. These details must be published before collection begins.',
				),
			),
			array(
				'id' => 'teachers',
				'eyebrow' => '03 / For teachers',
				'title' => 'Your explanation is part of the knowledge',
				'paragraphs' => array(
					'A performance lets us hear a result. A teacher helps us understand what to listen for, why a passage needs attention and how a student might improve it. Your explanations, demonstrations and judgement give the research its musical context. Helping document that knowledge could support future learners and researchers you may never meet.',
					'We want teachers to help shape the vocabulary, review annotations, identify disagreements and evaluate whether future tools are useful. A preferred approach from one ostad should not silently become a universal rule.',
					'The intended practice companion would follow a teacher’s chosen goals within its tested abilities. The teacher would interpret its reports and decide the next step. We do not aim to replace the ostad or reproduce the full human relationship through which music is taught.',
					'Teacher participation, permissions and any credit for public contributions must be set out in separate teacher terms. Those terms must address recording, research, licensing and applicable rights. They are not established by this explanation page. Public acknowledgement of a teacher must never identify a student without appropriate permission.',
				),
			),
			array(
				'id' => 'example',
				'eyebrow' => '04 / An illustrative learning journey',
				'title' => 'Learning riz: the journey matters',
				'paragraphs' => array(
					'Imagine a student learning riz (ریز) on setar. The teacher demonstrates the intended effect, listens to the student, identifies a difficulty and offers an exercise. Across later attempts and lessons, the student gradually develops the technique.',
					'The sequence below is hypothetical. It contains no actual student recording or research result.',
				),
				'steps' => array(
					array(
						'Demonstration',
						'The ostad provides an example appropriate to this instrument, student and teaching approach.',
					),
					array(
						'First attempt',
						'The student tries. The recording preserves what happened, without assuming that an automated score can explain it.',
					),
					array(
						'Explanation and correction',
						'The teacher identifies, for example, unevenness in the intended sound and explains what to change. The annotation links that explanation to the relevant passage.',
					),
					array(
						'Another attempt',
						'The student repeats the exercise. Reviewers can compare attempts and record the teacher’s assessment, including uncertainty.',
					),
					array(
						'Progress over time',
						'Later lessons show which difficulties persist, what changes and which guidance was offered. Improvement following advice is evidence to investigate; it does not alone prove that the advice caused it.',
					),
				),
			),
			array(
				'id' => 'research',
				'eyebrow' => '05 / From evidence to useful tools',
				'title' => 'A dataset is the first step',
				'paragraphs' => array(
					'We propose a longitudinal, teacher-annotated dataset: linked observations of learning across time. We hope to develop a much larger and more diverse resource than a small performance collection, while recognising that useful scale depends on annotation quality, permissions and representation—not hours alone.',
				),
				'steps' => array(
					array(
						'Collect with permission',
						'Capture only eligible material under the applicable participant permissions. Keep source recordings confidential with restricted access.',
					),
					array(
						'Describe and connect',
						'Align relevant audio/video passages with explanations, musical events, practice tasks and later attempts. Record instrument, teaching context, annotation source and uncertainty.',
					),
					array(
						'Build the alphabet',
						'Develop descriptions of techniques, ornaments, pitch relationships, rhythmic patterns, dastgahs, gushehs and melodic movement with musicians. Preserve stylistic differences and disagreement.',
					),
					array(
						'Make knowledge retrievable',
						'Build ways to find related examples, compare passages and study learning trajectories. Test models on students and teachers absent from their training data, with clear reporting of limits.',
					),
					array(
						'Evaluate useful applications',
						'Investigate teacher-guided practice support, summaries for the next lesson, and tools for musicological research. These are future possibilities, not validated or available services.',
					),
				),
			),
			array(
				'id' => 'companion',
				'eyebrow' => '06 / One possible application',
				'title' => 'Support between lessons',
				'paragraphs' => array(
					'A teacher sets a practice goal. During the week, a future companion might draw attention to a recurring difficulty, suggest a teacher-approved exercise or retrieve a relevant example. Before the next lesson, it could summarise what was attempted and which suggestions it offered.',
					'The ostad remains responsible for musical direction. The system should express uncertainty and avoid presenting a stylistic preference as a fact. Audio may help study sound and timing; reliable feedback about physical technique may also require suitable video and human assessment.',
					'These capabilities would require careful testing across instruments, skill levels, teaching traditions and recording conditions. We make no promise that the system can recognise every gusheh, assess every technique or understand musical expression as a human teacher does.',
				),
			),
			array(
				'id' => 'openness',
				'eyebrow' => '07 / Open work, protected lessons',
				'title' => 'Share the foundations responsibly',
				'paragraphs' => array(
					'Our aim is to develop software, documentation and research methods openly, with a future public GitHub repository. Public research datasets would be released only where privacy, permissions and other rights allow. No dataset or repository is announced as available by this page.',
					'Private source recordings and a public research resource are different things. Removing a name is not enough: faces, voices, personal conversation and combinations of details may identify someone. Even extracted measurements or model outputs need appropriate risk assessment. <a href="#project-source-4">[4]</a>',
					'We intend suitable public resources to support both academic and commercial work, including products made by Delnavazan or others, with appropriate attribution. CC BY 4.0 is one licence under consideration for suitable dataset material; it allows commercial reuse and adaptation with attribution, but does not override privacy or third-party rights. The final licences have not been selected. <a href="#project-source-5">[5]</a>',
					'Publicly distributed copies cannot reliably be recalled, and compliant recipients’ Creative Commons permissions cannot simply be revoked. Any proposed public contribution therefore needs a clear explanation of these limits before release. Keeping identifiable lessons confidential is an essential part of this distinction.',
				),
			),
			array(
				'id' => 'context',
				'eyebrow' => '08 / Part of a wider field',
				'title' => 'Build with existing research',
				'paragraphs' => array(
					'We are not starting Persian music computing from zero. Nava’s maintainers describe approximately 40 hours of audio labelled for seven dastgahs and five instruments. It supports instrument and dastgah recognition; its current access conditions specify academic and research use. <a href="#project-source-2">[2]</a>',
					'The KUG Dastgāhi Corpus (KDC) provides another important example: a corpus of professional performances for computational research, developed with musicians involved in research design and evaluation. <a href="#project-source-3">[3]</a>',
					'Our proposed emphasis is the teaching interaction and the student’s development over time. We welcome collaboration with existing projects. Their material would only be combined or redistributed where its own licences and permissions allow. Referencing a project does not imply its endorsement or partnership.',
				),
			),
			array(
				'id' => 'questions',
				'eyebrow' => '09 / Before you decide',
				'title' => 'Practical questions',
				'faq' => array(
					array(
						'Are classes being recorded for this Project now?',
						'Recording for this Project is currently disabled on this website. Visiting this page does not start recording. Any future activation requires the applicable permissions, notices and operational safeguards.',
					),
					array(
						'Can I study without joining the research?',
						'Student research participation is optional and separate from lesson-recording permission. The Project’s aim is to let students make an informed research choice without treating enrolment as agreement.',
					),
					array(
						'Can I change my mind?',
						'Account Settings is the place for available recording and research controls. If a control is unavailable, contact Delnavazan so your request can be addressed. Stopping future capture, stopping further use of identifiable material and deleting existing material are separate actions. The applicable notices must explain the process and limits before collection begins.',
					),
					array(
						'What about children?',
						'This page does not give permission on behalf of a child. Appropriate capacity/guardian arrangements and child-specific safeguards must be established before any child’s material is collected for the Project.',
					),
					array(
						'How long will recordings be kept?',
						'The retention schedule is not finalised. Our research has a long horizon, but that is not a blanket justification for storing identifiable recordings forever. The schedule must address ongoing need, review, deletion and copies or backups. OAIC guidance explains these principles where Australian privacy law applies. [4]',
					),
					array(
						'Will my mistakes or my teacher’s lessons appear on GitHub?',
						'That is not the intended use of confidential lesson recordings. Any suitable public resource would undergo separate privacy, permission and rights checks. Public examples could instead be separately commissioned demonstrations expressly authorised for release.',
					),
					array(
						'Can businesses use the results?',
						'That is our intention for suitably licensed public resources. Commercial reuse would not itself grant access to private lessons, permission to identify participants or an endorsement from Delnavazan or a teacher.',
					),
					array(
						'Does this page change my agreement?',
						'No. It explains the Project’s direction and is not a recording permission form, privacy policy or teacher contract. Read the applicable published notices and terms before making a decision.',
					),
				),
			),
			array(
				'id' => 'join',
				'eyebrow' => '10 / A shared undertaking',
				'title' => 'Help shape what comes next',
				'paragraphs' => array(
					'We invite musicians, teachers, students, ethnomusicologists, researchers and developers to question the approach and help improve it. Contributions could include defining musical concepts, reviewing annotations, designing evaluations, improving accessibility or building research software.',
					'We are beginning with a conversation and a practical foundation. If this grows into something enduring, it will be because a community shaped it together.',
				),
			),
		),
		'references' => 'Sources and further reading',
		'reference_note' => 'Sources provide background; they do not establish Delnavazan’s implementation, legal compliance, partnerships or research results. Descriptions checked 1 November 2026.',
		'policy' => 'Read the applicable policies',
		'privacy' => 'Privacy policy',
		'terms' => 'Terms and conditions',
		'account' => 'Student Account Settings',
		'onboarding' => 'Teacher onboarding',
		'contact' => 'Ask a question or discuss contributing',
		'notices' => 'Published recording and research notices',
		'no_notices' => 'No current approved recording or research notice is available from the website. A project explanation is not a substitute for the applicable notice.',
		'notice_note' => 'These entries identify currently published versions; consult the full notices shown with the relevant choice or teacher terms.',
	),
	'fa' => array(
		'language' => 'فارسی',
		'intro' => 'سنتی زنده، آینده‌ای مشترک',
		'title' => 'الفبایی برای آیندهٔ موسیقی ایرانی',
		'lead' => '«پروژه» ابتکار بلندمدت دلنوازان برای کمک به درک رایانه‌ای موسیقی ایرانی از مسیر آموزش و یادگیری آن است. افق ما ده تا پانزده سال آینده است؛ نقطهٔ آغازمان دانشی است که میان استاد و هنرجو جریان دارد.',
		'status' => 'در حال توسعه · ضبط برای پروژه در این وب‌سایت غیرفعال است. خواندن این صفحه ضبط را فعال نمی‌کند و به معنای پذیرش مشارکت در پژوهش نیست.',
		'nav' => array(
			'vision' => 'چرا این پروژه؟',
			'students' => 'برای هنرجویان',
			'teachers' => 'برای مدرسان',
			'example' => 'نمونهٔ یادگیری',
			'research' => 'رویکرد پژوهشی',
			'questions' => 'پرسش‌ها',
		),
		'sections' => array(
			array(
				'id' => 'vision',
				'eyebrow' => '۰۱ / هدف',
				'title' => 'دانش را به نسل بعد برسانیم',
				'paragraphs' => array(
					'بخشی از دانش موسیقی ایرانی با شنیدن، دیدن، تمرین و درک تدریجی منتقل می‌شود. شخصیت یک جمله، شکل یک تزیین و معنای اصلاح استاد همیشه در نت‌نویسی به‌تنهایی نمی‌گنجد.',
					'ردیف و استادانی که آن را منتقل کردند، از جمله میرزا عبدالله، یادآور مسئولیتی مشترک میان نسل‌ها هستند. یونسکو ردیف را مجموعه‌ای توصیف می‌کند که از استاد به شاگرد و از راه آموزش شفاهی منتقل می‌شود و حامل عمل و فلسفهٔ موسیقایی است. <a href="#project-source-1">[۱]</a>',
					'از این مسئولیت الهام می‌گیریم. ادعا نداریم که ردیفی تازه می‌سازیم یا جای دانش موسیقی‌دانان را می‌گیریم. می‌خواهیم ابزارهایی فراهم کنیم که به ادامهٔ حیات این سنت کمک کنند.',
					'«الفبا» برای ما استعاره‌ای از پایه‌های مشترک است: توصیف‌ها، نمونه‌های توضیح‌گذاری‌شده، روش‌های پژوهش و نرم‌افزارهایی که به بازنمایی موسیقی ایرانی کمک کنند و برای روایت‌های مختلف ردیف، مکتب‌ها و انتخاب‌های هنری جا بگذارند.',
				),
			),
			array(
				'id' => 'students',
				'eyebrow' => '۰۲ / برای هنرجویان',
				'title' => 'یادگیری شما شایستهٔ مراقبت است',
				'paragraphs' => array(
					'کلاس جای آزمودن، تردید و اشتباه‌کردن است. هدف پژوهشی ما شناخت فرایند رشد موسیقایی است، نه نمایش عمومی اجرای شما.',
					'اگر ضبط در آینده راه‌اندازی شود، ممکن است تصویر، صدا، نوازندگی شما، نمایش‌های استاد و گفت‌وگوی کلاس را در بر بگیرد. آماده‌سازی پژوهشی می‌تواند شامل پیاده‌سازی گفتار، اندازه‌گیری ویژگی‌های موسیقایی و توضیح‌گذاری بخش‌های مرتبط باشد.',
					'اجازهٔ ضبط کلاس با اجازهٔ استفادهٔ پژوهشی یا آموزش مدل جداست. مشارکت پژوهشی هنرجو اختیاری است. خواندن این صفحه، ثبت‌نام در کلاس یا پذیرش شرایط عمومی سایت به‌خودی‌خود به معنای پیوستن به پژوهش نیست. <a href="#project-source-6">[۶]</a>',
					'مرز موردنظر ما این است که ضبط خصوصی کلاس به‌عنوان تبلیغ، ویدیوی آموزشی عمومی یا سرگرمی منتشر نشود. مشارکت پژوهشی نیز تضمین دریافت همراه هوشمند، بازخورد شخصی یا محصول آینده نیست.',
					'پیش از آغاز ضبط، اطلاعیه‌های مربوط باید محتوای ضبط، افراد دارای دسترسی، کاربردها، محل و مدت نگهداری و روش تغییر انتخاب یا درخواست حذف را روشن کنند. مدت نهایی نگهداری و جزئیات انصراف و حذف هنوز تعیین نشده است. این جزئیات باید پیش از آغاز گردآوری منتشر شوند.',
				),
			),
			array(
				'id' => 'teachers',
				'eyebrow' => '۰۳ / برای مدرسان',
				'title' => 'توضیح شما بخشی از دانش است',
				'paragraphs' => array(
					'اجرا نتیجه را به گوش ما می‌رساند؛ استاد نشان می‌دهد به چه چیزی گوش کنیم، چرا بخشی نیاز به توجه دارد و چگونه می‌توان بهتر شد. توضیح، نمایش و قضاوت شما به داده‌ها زمینهٔ موسیقایی می‌دهد. ثبت این دانش می‌تواند به هنرجویان و پژوهشگران آینده‌ای کمک کند که شاید هرگز آن‌ها را نبینید.',
					'می‌خواهیم مدرسان در تعریف واژگان، بازبینی توضیح‌ها، ثبت اختلاف‌نظرها و ارزیابی ابزارهای آینده نقش داشته باشند. روش مطلوب یک استاد نباید بی‌صدا به قانون همگانی تبدیل شود.',
					'همراه تمرین موردنظر، در محدودهٔ توانایی آزموده‌شدهٔ خود، از هدف‌های انتخاب‌شدهٔ استاد پیروی می‌کند. تفسیر گزارش و تعیین گام بعدی با استاد می‌ماند. هدف ما جایگزینی استاد یا بازسازی کامل رابطهٔ انسانی آموزش نیست.',
					'مسئولیت‌های مشارکت، مجوزها و شیوهٔ قدردانی عمومی باید در شرایط جداگانهٔ مدرسان مشخص شوند؛ از جمله ضبط، پژوهش، مجوز انتشار و حقوق مربوط. این صفحه چنین قراردادی ایجاد نمی‌کند. نام‌بردن از مدرس نیز نباید بدون مجوز مناسب هویت هنرجو را آشکار کند.',
				),
			),
			array(
				'id' => 'example',
				'eyebrow' => '۰۴ / یک مسیر فرضی یادگیری',
				'title' => 'یادگیری ریز؛ مسیر هم اهمیت دارد',
				'paragraphs' => array(
					'هنرجویی را تصور کنید که ریز سه‌تار می‌آموزد. استاد نمونه می‌زند، به تلاش هنرجو گوش می‌دهد، دشواری را توضیح می‌دهد و تمرینی پیشنهاد می‌کند. هنرجو در کوشش‌ها و کلاس‌های بعدی به‌تدریج پیش می‌رود.',
					'نمونهٔ زیر فرضی است و ضبط واقعی هنرجو یا نتیجهٔ پژوهش نیست.',
				),
				'steps' => array(
					array(
						'نمایش استاد',
						'استاد نمونه‌ای متناسب با ساز، سطح هنرجو و رویکرد آموزشی خود ارائه می‌کند.',
					),
					array(
						'تلاش نخست',
						'هنرجو امتحان می‌کند. آنچه رخ داده ثبت می‌شود، بدون اینکه فرض کنیم یک امتیاز خودکار می‌تواند آن را توضیح دهد.',
					),
					array(
						'توضیح و اصلاح',
						'استاد مثلاً نایکنواختی صدای موردنظر را مشخص می‌کند و تغییر لازم را توضیح می‌دهد. توضیح به بخش مرتبط اجرا پیوند می‌خورد.',
					),
					array(
						'تلاش دوباره',
						'هنرجو تمرین را تکرار می‌کند. بازبینان می‌توانند تلاش‌ها و ارزیابی استاد را همراه با موارد نامطمئن مقایسه کنند.',
					),
					array(
						'پیشرفت در طول زمان',
						'جلسه‌های بعدی تغییرها، دشواری‌های ماندگار و راهنمایی‌ها را نشان می‌دهند. بهترشدن پس از یک توصیه به‌تنهایی اثبات نمی‌کند که علت پیشرفت همان توصیه بوده است.',
					),
				),
			),
			array(
				'id' => 'research',
				'eyebrow' => '۰۵ / از شواهد تا ابزار',
				'title' => 'مجموعه‌داده گام نخست است',
				'paragraphs' => array(
					'پیشنهاد ما مجموعه‌داده‌ای طولی با توضیح‌گذاری مدرسان است؛ یعنی مشاهده‌های مرتبط از یادگیری در گذر زمان. به منبعی بسیار بزرگ‌تر و متنوع‌تر از مجموعه‌ای کوچک از اجراها فکر می‌کنیم؛ اما کیفیت توضیح‌ها، مجوزها و تنوع مشارکت‌کنندگان به‌اندازهٔ تعداد ساعت‌ها اهمیت دارد.',
				),
				'steps' => array(
					array(
						'گردآوری با مجوز',
						'فقط محتوای واجد شرایط و دارای مجوزهای لازم گردآوری شود. ضبط‌های اصلی محرمانه و با دسترسی محدود بمانند.',
					),
					array(
						'توصیف و پیوند',
						'بخش‌های مرتبط صدا و تصویر با توضیح، رویداد موسیقایی، تکلیف تمرین و تلاش‌های بعدی هم‌تراز شوند. ساز، زمینهٔ آموزش، منبع توضیح و عدم‌قطعیت ثبت شود.',
					),
					array(
						'ساخت الفبا',
						'با همراهی موسیقی‌دانان، شیوهٔ توصیف تکنیک، تزیین، روابط زیر و بمی، ریتم، دستگاه، گوشه و حرکت ملودی توسعه یابد. تفاوت سبک‌ها و اختلاف‌نظرها حفظ شود.',
					),
					array(
						'بازیابی دانش',
						'ابزار یافتن نمونه‌های مرتبط، مقایسهٔ بخش‌ها و بررسی مسیر یادگیری ساخته شود. مدل‌ها با هنرجویان و مدرسانی ارزیابی شوند که در دادهٔ آموزش نبوده‌اند و محدودیت‌ها روشن گزارش شود.',
					),
					array(
						'ارزیابی کاربردها',
						'پشتیبانی تمرین با هدایت استاد، خلاصهٔ جلسهٔ بعد و ابزار پژوهش موسیقی بررسی شوند. این‌ها امکان‌های آینده‌اند، نه خدمات موجود یا تأییدشده.',
					),
				),
			),
			array(
				'id' => 'companion',
				'eyebrow' => '۰۶ / یک کاربرد احتمالی',
				'title' => 'پشتیبانی میان دو کلاس',
				'paragraphs' => array(
					'استاد هدف تمرین را تعیین می‌کند. همراه آینده می‌تواند در طول هفته به دشواری تکرارشونده توجه دهد، تمرین موردتأیید استاد پیشنهاد کند یا نمونه‌ای مرتبط بیابد. پیش از کلاس بعد نیز می‌تواند تلاش‌ها و پیشنهادهای ارائه‌شده را خلاصه کند.',
					'جهت موسیقایی همچنان با استاد است. سامانه باید عدم‌قطعیت را بیان کند و سلیقهٔ سبکی را حقیقت قطعی جلوه ندهد. صدا می‌تواند به بررسی کیفیت شنیداری و زمان‌بندی کمک کند؛ ارزیابی قابل‌اعتماد حالت بدن و تکنیک حرکتی ممکن است به تصویر مناسب و بررسی انسانی نیاز داشته باشد.',
					'این توانایی‌ها به آزمون دقیق در سازها، سطح‌ها، مکتب‌ها و شرایط ضبط متفاوت نیاز دارند. وعده نمی‌دهیم که سامانه همهٔ گوشه‌ها را تشخیص دهد، هر تکنیکی را ارزیابی کند یا بیان موسیقایی را مانند استاد بفهمد.',
				),
			),
			array(
				'id' => 'openness',
				'eyebrow' => '۰۷ / پژوهش باز، کلاس محرمانه',
				'title' => 'پایه‌ها را مسئولانه به اشتراک بگذاریم',
				'paragraphs' => array(
					'هدف ما توسعهٔ باز نرم‌افزار، مستندات و روش‌های پژوهش و ایجاد مخزن عمومی گیت‌هاب در آینده است. انتشار عمومی مجموعه‌داده تنها در صورت سازگاری با حریم خصوصی، مجوزها و سایر حقوق انجام می‌شود. این صفحه اعلام آماده‌بودن مخزن یا مجموعه‌داده نیست.',
					'ضبط خصوصی با منبع پژوهشی عمومی فرق دارد. حذف نام کافی نیست؛ چهره، صدا، گفت‌وگوی شخصی یا ترکیب جزئیات ممکن است فرد را قابل‌شناسایی کند. حتی ویژگی‌های استخراج‌شده و خروجی مدل نیازمند ارزیابی مناسب خطر هستند. <a href="#project-source-4">[۴]</a>',
					'قصد داریم منابع عمومیِ مناسب با ذکر منبع، هم برای پژوهش و هم برای کاربرد تجاری، از جمله محصولات دلنوازان یا دیگران، قابل‌استفاده باشند. CC BY 4.0 یکی از گزینه‌های بررسی‌شده برای محتوای مناسب است؛ استفادهٔ تجاری و تغییر را با ذکر منبع مجاز می‌کند، اما حقوق حریم خصوصی و اشخاص ثالث را کنار نمی‌زند. مجوزهای نهایی هنوز انتخاب نشده‌اند. <a href="#project-source-5">[۵]</a>',
					'بازپس‌گیری همهٔ نسخه‌های عمومی قابل‌تضمین نیست و مجوز کریتیو کامنزِ استفاده‌کنندهٔ پایبند به شرایط را نمی‌توان به‌سادگی لغو کرد. این محدودیت باید پیش از هر انتشار توضیح داده شود. محرمانه‌ماندن درس‌های قابل‌شناسایی بخش اساسی این تفکیک است.',
				),
			),
			array(
				'id' => 'context',
				'eyebrow' => '۰۸ / در کنار پژوهش‌های دیگر',
				'title' => 'از دانش موجود بسازیم',
				'paragraphs' => array(
					'پژوهش رایانشی موسیقی ایرانی از صفر آغاز نمی‌شود. گردانندگان مجموعهٔ نوا حدود ۴۰ ساعت صدا با برچسب هفت دستگاه و پنج ساز را معرفی می‌کنند. تمرکز آن تشخیص ساز و دستگاه است و شرایط دسترسی فعلی، استفادهٔ دانشگاهی و پژوهشی را تعیین می‌کند. <a href="#project-source-2">[۲]</a>',
					'مجموعهٔ KUG Dastgāhi Corpus یا KDC نیز نمونه‌ای مهم از اجراهای حرفه‌ای برای پژوهش رایانشی است که در طراحی و ارزیابی پژوهش آن موسیقی‌دانان نقش دارند. <a href="#project-source-3">[۳]</a>',
					'تمرکز پیشنهادی ما تعامل آموزشی و رشد هنرجو در طول زمان است. از همکاری استقبال می‌کنیم؛ ترکیب یا بازنشر منابع دیگر تنها با رعایت مجوزهای خودشان ممکن است. ارجاع به این پروژه‌ها به معنای همکاری رسمی یا تأیید دلنوازان از سوی آن‌ها نیست.',
				),
			),
			array(
				'id' => 'questions',
				'eyebrow' => '۰۹ / پیش از تصمیم',
				'title' => 'پرسش‌های کاربردی',
				'faq' => array(
					array(
						'آیا اکنون کلاس‌ها برای پروژه ضبط می‌شوند؟',
						'ضبط برای این پروژه در وب‌سایت غیرفعال است. دیدن این صفحه ضبط را آغاز نمی‌کند. فعال‌سازی آینده به اطلاعیه‌ها، مجوزها و سازوکارهای اجرایی لازم نیاز دارد.',
					),
					array(
						'آیا می‌توانم بدون مشارکت پژوهشی درس بخوانم؟',
						'مشارکت پژوهشی هنرجو اختیاری و از اجازهٔ ضبط کلاس جداست. هدف پروژه انتخاب آگاهانه است؛ ثبت‌نام به معنای پذیرش پژوهش نیست.',
					),
					array(
						'آیا می‌توانم نظرم را تغییر دهم؟',
						'کنترل‌های در دسترس ضبط و پژوهش در تنظیمات حساب قرار دارند. اگر کنترلی در دسترس نبود، با دلنوازان تماس بگیرید تا درخواستتان بررسی شود. توقف ضبط آینده، توقف استفادهٔ بعدی از اطلاعات قابل‌شناسایی و حذف اطلاعات قبلی اقدام‌های جداگانه‌اند. روند و محدودیت‌ها باید پیش از گردآوری توضیح داده شوند.',
					),
					array(
						'وضعیت کودکان چیست؟',
						'این صفحه از طرف کودک اجازه‌ای صادر نمی‌کند. پیش از گردآوری محتوای کودک باید ترتیبات مناسبِ ظرفیت تصمیم‌گیری یا نمایندهٔ قانونی و مراقبت‌های ویژهٔ کودک برقرار شوند.',
					),
					array(
						'ضبط‌ها چه مدت نگهداری می‌شوند؟',
						'برنامهٔ نهایی نگهداری تعیین نشده است. افق بلند پژوهش مجوز همیشگی برای نگهداری اطلاعات قابل‌شناسایی نیست. نیاز مستمر، بازبینی، حذف و نسخه‌های پشتیبان باید در برنامه مشخص باشند. راهنمای OAIC اصول مربوط را در صورت شمول قانون حریم خصوصی استرالیا توضیح می‌دهد. [۴]',
					),
					array(
						'آیا اشتباه من یا کلاس استادم در گیت‌هاب منتشر می‌شود؟',
						'این کاربرد موردنظر ضبط‌های محرمانه نیست. هر منبع عمومیِ مناسب به بررسی جداگانهٔ حریم خصوصی، مجوز و حقوق نیاز دارد. برای نمونه‌های عمومی می‌توان نمایش‌هایی جداگانه و با مجوز صریح انتشار تهیه کرد.',
					),
					array(
						'آیا شرکت‌ها می‌توانند از نتایج استفاده کنند؟',
						'برای منابع عمومی با مجوز مناسب، قصد ما همین است. استفادهٔ تجاری به‌خودی‌خود اجازهٔ دسترسی به کلاس خصوصی، شناسایی مشارکت‌کننده یا ادعای تأیید از سوی استاد یا دلنوازان نمی‌دهد.',
					),
					array(
						'آیا این صفحه توافق من را تغییر می‌دهد؟',
						'خیر. این توضیح مسیر پروژه است، نه فرم اجازهٔ ضبط، سیاست حریم خصوصی یا قرارداد مدرس. پیش از تصمیم، اطلاعیه‌ها و شرایط منتشرشدهٔ مرتبط را بخوانید.',
					),
				),
			),
			array(
				'id' => 'join',
				'eyebrow' => '۱۰ / کاری مشترک',
				'title' => 'در شکل‌دادن آینده همراه شوید',
				'paragraphs' => array(
					'از موسیقی‌دانان، مدرسان، هنرجویان، پژوهشگران موسیقی، متخصصان یادگیری ماشین و توسعه‌دهندگان دعوت می‌کنیم رویکرد را نقد و بهتر کنند. تعریف مفاهیم، بازبینی توضیح‌ها، طراحی ارزیابی، بهبود دسترس‌پذیری و ساخت نرم‌افزار پژوهشی از مسیرهای همکاری‌اند.',
					'با گفت‌وگو و پایه‌ای عملی آغاز می‌کنیم. اگر چیزی ماندگار از این تلاش شکل بگیرد، حاصل همراهی یک جامعه خواهد بود.',
				),
			),
		),
		'references' => 'منابع و مطالعهٔ بیشتر',
		'reference_note' => 'منابع برای زمینهٔ بحث‌اند و اجرای فنی، انطباق حقوقی، همکاری رسمی یا نتایج پژوهشی دلنوازان را تأیید نمی‌کنند. توصیف منابع در ۱ نوامبر ۲۰۲۶ بررسی شده است.',
		'policy' => 'سیاست‌های مرتبط را بخوانید',
		'privacy' => 'حریم خصوصی',
		'terms' => 'شرایط و ضوابط',
		'account' => 'تنظیمات حساب هنرجو',
		'onboarding' => 'آغاز همکاری مدرس',
		'contact' => 'پرسش یا پیشنهاد همکاری',
		'notices' => 'اطلاعیه‌های منتشرشدهٔ ضبط و پژوهش',
		'no_notices' => 'اطلاعیهٔ جاری و تأییدشده‌ای برای ضبط یا پژوهش از وب‌سایت در دسترس نیست. توضیح پروژه جای اطلاعیهٔ مربوط را نمی‌گیرد.',
		'notice_note' => 'این فهرست نسخه‌های جاری منتشرشده را مشخص می‌کند؛ متن کامل را کنار انتخاب مرتبط یا شرایط مدرس بخوانید.',
	),
);
