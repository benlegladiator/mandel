<?php
// Script d'importation automatique des données initiales (déplacé dans backend/parse_products.php)
require_once __DIR__ . '/config/db.php';

$data = <<<DATA
1	Couché 2 FACE128G 64X92 250 FEUILLES	Nkozoa	 9	IMPRIMERIE COUCHE	RAME	 20,000		 22,000	
2	Couché 2 FACE 150G 64X90 250 FEUILLES	Nkozoa	 12	IMPRIMERIE COUCHE	RAME	 21,000		 24,000	
3	Couché 2 FACE 170G 64X90 250 FEUILLE	Nkozoa	 6	IMPRIMERIE COUCHE	RAME	 27,000		 29,000	
4	Couché 2 FACE 200G 64X90 250FEUILLE	Nkozoa	 49	IMPRIMERIE COUCHE	RAME	 15,000		 18,000	
5	Couché 2 FACE 250G 64X90 125 FEUILLE	Nkozoa	 19	IMPRIMERIE COUCHE	RAME	 20,500		 22,500	
6	Couché 2 FACE 300G 64X90 125 FEUILLE	Nkozoa	 26	IMPRIMERIE COUCHE	RAME	 27,000		 29,000	
7	Couché 2 FACE 350G 64X90 125 FEUILLE	Nkozoa	 6	IMPRIMERIE COUCHE	RAME	 31,500		 33,000	
8	Couché 2 FACE 128G A4 100 FEUILLE	Nkozoa	 19	COUCHE A4	RAMETTE			 1,500	
9	Couché 2 FACE 150G A4 100 FEUILLES	Nkozoa	 15	COUCHE A4	RAMETTE			 1,800	
10	Couché 2 FACE 170G A4 100 FEUILLE	Nkozoa	 25	COUCHE A4	RAMETTE			 2,000	
11	Couché 2 FACE 200G A4 100FEUILLE	Nkozoa	 3	COUCHE A4	RAMETTE			 2,500	
12	Couché 2 FACE 250G A4 100 FEUILLE	Nkozoa	 7	COUCHE A4	RAMETTE			 3,000	
13	Couché 2 FACE 300G A4 100FEUILLE	Nkozoa	 4	COUCHE A4	RAMETTE			 3,500	
14	Couché 2 FACE 350G A4 100 FEUILLE	Nkozoa	 3	COUCHE A4	RAMETTE			 4,000	
15	Couché 2 FACE 300G A3 100 FEUILLE	Nkozoa	 15	COUCHE A3	RAMETTE			 7,000	
16	Couché 2 FACE 170G A3 100 FEUILLE	Nkozoa	 9	COUCHE A3	RAMETTE			 45,000	
17	Couché 2 FACE 200G A3 100 FEUILLE	Nkozoa	 12	COUCHE A3	RAMETTE			 5,500	
18	Couché 2 FACE 250G A3 100 FEUILLE	Nkozoa	 8	COUCHE A3	RAMETTE			 6,000	
19	Couché 1FACE 210G 64X90 125 FEUILLE	Nkozoa	 9	IMPRIMERIE COUCHE	RAME			 15,000	
20	Couché 1FACE 270G 64X90 125 FEUILLE	Nkozoa	 9	IMPRIMERIE COUCHE	RAME			 21,500	
21	Couché 1FACE 350G 64X90 100 FEUILLE	Nkozoa	 5	IMPRIMERIE COUCHE	RAME			 27,000	
22	SATINE BLANC A2	Nkozoa	 14	SATIN A2	RAMETTE			 7,000	
23	SATINE BLANC 64X90	Nkozoa	 5	SATINE 	RAME			 14,000	
24	SATINE VERT	Nkozoa	 1	SATINE 	RAME			 16,000	
25	OFFSET PAPER 80G	Nkozoa	 10	OFFSET A2	RAMETTE	 14,000		 14,500	
26	OFFSET INSAT 70G	Nkozoa	 25	OFFSE A2	RAMETTE	 12,500		 13,000	
27	OFFSET 70G UPM 	Nkozoa	 14	OFFSET A2	RAMETTE	 11,750		 12,500	
28	Papier kraft	Nkozoa	 1	KRAFT	KG	 37,000		 40,000	
29	WINNER A4 500 FEUILLE	Nkozoa	 60	PAPIER PHOTOCOPIE A4	RAMETTE	 12,000		 13,000	
30	MIILK 80G A3 500 FEUILLE	Nkozoa	 9	PAPIER PHOTOCOPIE A4	RAMETTE	 3,300		 4,000	
31	MIILK 75 G A4 500FEUILLE	Nkozoa	 5	PAPIER PHOTOCOPIE A4	RAMETTE	 12,500		 13,000	
32	Transparent bleu A4	Nkozoa	 4	FOUNITURE DE BUREAU	PAQUET				
33	transparent blanc A4 100 FEUILLE	Nkozoa	 7	FOUNITURE DE BUREAU	PAQUET				
34	malmero A4 100 FEUILLE	Nkozoa	 6	FOUNITURE DE BUREAU	PAQUET				
35	Chemise de 90	Nkozoa	 17	CHEMISE	PAQUET				
36	CHEMISE DE 100	Nkozoa	 10	CHEMISE	PAQUET				
37	CHEmise de 75	Nkozoa	 11	CHEMISE	PAQUET				
38	Sous chemise de 200	Nkozoa	 2	SOUS CHEMISE	PAQUET				
39	SOUS chemise de 150	Nkozoa	 10	SOUS CHEMISE	PAQUET				
40	Bic bleu	Nkozoa	 75	FOUNITURE DE BUREAU	PIECE				
41	bic rouge	Nkozoa	 75	FOUNITURE DE BUREAU	PIECE				
42	craie couleur	Nkozoa	 3	FOUNITURE DE BUREAU	BOITE				
43	craie blanche	Nkozoa	 19	FOUNITURE DE BUREAU	BOITE				
44	bristol	Nkozoa	 10	FOUNITURE DE BUREAU	RAMETTE				
45	serre format 8mm	Nkozoa	 2	FOUNITURE DE BUREAU	PAQUET				
46	serre format 6mm	Nkozoa	 1	FOUNITURE DE BUREAU	PAQUET				
47	 SPIRAL DE 12	Nkozoa	 2	FOUNITURE DE BUREAU	PAQUET				
48	SPIRAL 10	Nkozoa	 1	FOUNITURE DE BUREAU	PAQUET				
49	ENVELOPPE BLANC A5 50 FEUILLE	Nkozoa	 6	FOUNITURE DE BUREAU	PAQUET				
50	ENVELOPPE BLANC LONG MIILK A6 50 FEUILLE	Nkozoa	 34	FOUNITURE DE BUREAU	PAQUET				
51	ENVELOPPE KAKI A5 50 FEUILLE	Nkozoa	 12	FOUNITURE DE BUREAU	PAQUET				
52	ENVELOPPE KAKI A4 50 FEUILLE	Nkozoa	 5	FOUNITURE DE BUREAU	PAQUET				
53	ENVELOPPE KAKI A3 FEUILLE	Nkozoa	 20	FOUNITURE DE BUREAU	PAQUET				
54	ENVELOPPE BLANC COURT A6 50 FEUILLE	Nkozoa	 11	FOUNITURE DE BUREAU	PAQUET				
55	CALQUE A4	Nkozoa	 5	FOUNITURE DE BUREAU	PAQUET				
56	CALQUEA3	Nkozoa	 10	FOUNITURE DE BUREAU	PAQUET				
57	BLOC NOTE AVEC BIC A5	Nkozoa	 10	FOUNITURE DE BUREAU	PIECE				
58	BLOC NOTE COULEUR A6	Nkozoa	 7	FOUNITURE DE BUREAU	PIECE				
59	bloc note VIP avec bic A5	Nkozoa	 5	FOUNITURE DE BUREAU	PIECE				
60	BLOC NOTE BUSSNESS A5	Nkozoa	 8	FOUNITURE DE BUREAU	PIECE				
61	BLOC NOTE BRILLANT B5	Nkozoa	 8	FOUNITURE DE BUREAU	PIECE				
62	BLOC NOTE	Nkozoa	 3	FOUNITURE DE BUREAU	PIECE				
63	sac a vin	Nkozoa	 6	FOUNITURE DE BUREAU	PIECE				
64	Tangui  0,33cl	Nkozoa	 234	EAU	BOUTEILLE				
65	Tangui 0,5 l	Nkozoa	 300	EAU	BOUTEILLE				
66	Tangui 1,5l	Nkozoa	 282	EAU	BOUTEILLE				
67	Vital  1,5l	Nkozoa	 246	EAU	BOUTEILLE				
68	Vital 10 l	Nkozoa	 174	EAU	BOUTEILLE				
69	Supermont 1,5L	Nkozoa	 282	EAU	BOUTEILLE				
70	Opur 0,5l	Nkozoa	 78	EAU	BOUTEILLE				
71	Essuie tout premium	Nkozoa	 7	ESSUIT TOUT	PIECE				
72	CORRECTEUR	Nkozoa	 19	FOUNITURE DE BUREAU	PIECE				
73	Jumbo basic	Nkozoa	 98	ESSUIT TOUT	PIECE				
74	Mouchoire de poche paquet de 10	Nkozoa	 30	ESSUIT TOUT	PAQUET				
75	Registre SIMPLE 200P	Nkozoa	 1	REGISTRE	UNITE				
76	Registre SIMPLE 300P	Nkozoa	 5	REGISTRE	UNITE				
77	Registre SIMPLE 400P	Nkozoa	 5	REGISTRE	UNITE				
78	Registre SIMPLE 500P	Nkozoa	 5	REGISTRE	UNITE				
79	Registre SIMPLE 800P	Nkozoa	 3	REGISTRE	UNITE				
80	Registre soleil 300P 	Nkozoa	 3	REGISTRE	UNITE				
81	Registre soleil 200P	Nkozoa	 4	REGISTRE	UNITE				
82	Registre soleil 500P	Nkozoa	 6	REGISTRE	UNITE				
83	Registre soleil 600P	Nkozoa	 5	REGISTRE	UNITE				
84	Registre soleil 400P	Nkozoa	 10	REGISTRE	UNITE				
85	Registre soleil 900P	Nkozoa	 5	REGISTRE	UNITE				
86	Registre soleil 1000P	Nkozoa	 5	REGISTRE	UNITE				
87	Papier filme	Nkozoa	 2	DIVERS	UNITE				
88	rame de papiers double A A4	Nkozoa	10	PAPIER PHOTOCOPIE A5	UNITE	 3,200			
89	encre horse pour tampon 360	Nkozoa	24	FOUNITURE DE BUREAU	UNITE	 125			
90	tampon encreur 240 	Nkozoa	12	FOUNITURE DE BUREAU	UNITE	 250			
91	manifold A5auto dupli 100	Nkozoa	5	FOUNITURE DE BUREAU	UNITE	 600			
92	facturier A6auto dupli  200 A6	Nkozoa	5	FOUNITURE DE BUREAU	UNITE	 300			
93	Scotch 100M amsua 60	Nkozoa	60	FOUNITURE DE BUREAU	UNITE	 267			
94	crayon conte avec gomme 240 DZ	Nkozoa	13	FOUNITURE DE BUREAU	DOUZAINE	 250			
95	Crayon conte sans gomme 240 DZ	Nkozoa	12	FOUNITURE DE BUREAU	DOUZAINE	 250			
96	gomme maped 50	Nkozoa	3	FOUNITURE DE BUREAU	PAQUET	 600			
97	gomme rotring 50 60	Nkozoa	3	FOUNITURE DE BUREAU	PAQUET	 800			
98	regle dure loupe 30cm 600	Nkozoa	60	FOUNITURE DE BUREAU	PIECE	 58			
99	ciseaux moyen 18 cm 600	Nkozoa	12	FOUNITURE DE BUREAU	PIECE	 300			
100	trombones 33 mm fax (500)	Nkozoa	20	FOUNITURE DE BUREAU	PIECE	 100			
101	Corrector retype 200	Nkozoa	10	FOUNITURE DE BUREAU	PIECE	 250			
102	bloc cubique imbox 48	Nkozoa	3	FOUNITURE DE BUREAU	PIECE	 800			
103	post it couleur 3X 3 480	Nkozoa	12	FOUNITURE DE BUREAU	PIECE	 125			
104	Enveloppe A6 long blanc miilk A6	Nkozoa	30	FOUNITURE DE BUREAU	PAQUET				
105	encre bleu	Nkozoa	1	FOUNITURE DE BUREAU	PIECE				
106	papier format inacopia A4	Nkozoa	20	FOUNITURE DE BUREAU	RAME				
107	SOULIGNEUR PQT DE 4	Nkozoa	15	FOUNITURE DE BUREAU	PAQUET	 450			
108	AGRAFFEUSE BEBE 516 	Nkozoa	10	FOUNITURE DE BUREAU	PIECE	 1,000			
109	AGRAFFE BEBE PQT 10	Nkozoa	2	FOUNITURE DE BUREAU	PAQUET	 700			
110	SCOTCH TRANSPARANT GM  CT 60	Nkozoa	1	FOUNITURE DE BUREAU	CARTONS	 20,000			
111	CHIER 400PAGES A4 FRACOPHONE WHITE	Nkozoa	20	CAHIER	CARTONS	 1,750			
112	CAHIER LEDGE 400 PAGES PLAINE LINE WHITE	Nkozoa	3	CAHIER	UNITE	 2,000			
113	CALCULATRICE CSHAL CT 816	Nkozoa	15	FOUNITURE DE BUREAU	UNITE	 1,800		2500	
114	PORTE STYLOS EN BOIS	Nkozoa	4	FOUNITURE DE BUREAU	UNITE	 2,600		5000	
115	TROMBONNES COULEUR+PORTE CLE	Nkozoa	2	FOUNITURE DE BUREAU	UNITE	 1,500		2000	
116	PORTE CLE EN BOITE PETIT MODEL	Nkozoa	2	FOUNITURE DE BUREAU	UNITE	 1,000		1500	
117	PORTE CLE EN BOITE GRAND MODEL	Nkozoa	2	FOUNITURE DE BUREAU	UNITE	 1,100		1500	
118	TROMBONNE COULEUR PETITE BOITE	Nkozoa	18	FOUNITURE DE BUREAU	UNITE	 350		500	
119	SOUS MAIN PLASTIQUE A4	Nkozoa	5	FOUNITURE DE BUREAU	UNITE	 1,100		1500	
120	SOUS MAIN PLASTIQUE A3	Nkozoa	3	FOUNITURE DE BUREAU	UNITE	 1,400		2000	
121	GAMELLE BOX DE 3	Nkozoa	2	FOUNITURE SCOLAIRE	PAQUET(3)	 1,600		2000	
122	TROMBONNE PETITE BOITE	Nkozoa	3	FOUNITURE DE BUREAU	UNITE	 600		900	
123	mon cahier d'activité  de mathématique 1 nathan	Nkozoa	5	LIVRES	UNITE	 850		 1,000	
124	Mon cahier d'activité  de mathématique 2bnathan	Nkozoa	5	LIVRES	UNITE	 850		 1,000	
125	majors en activite de language 2 majors	Nkozoa	5	LIVRES	UNITE	 850		 1,000	
126	je pratique le dessin et le coloriage 1ere annee 	Nkozoa	5	LIVRES	UNITE	 850		 1,000	
127	je decouvre le greque 4eme et 3eme eclosion	Nkozoa	2	LIVRES	UNITE	 4,250		 5,000	
128	Excelence en physique TLE D C jeremie T	Nkozoa	2	LIVRES	UNITE	 5,525		 6,500	
129	Excelence en phylosophie 2nde NMI	Nkozoa	3	LIVRES	UNITE	 3,825		 4,500	
130	Science de la vie et de la terre 1ereD wonkam cle	Nkozoa	2	LIVRES	UNITE	 5,525		 6,500	
131	excellence en physique 1ere C D E TI F BT SES IH ESF NMI	Nkozoa	1	LIVRES	UNITE	 4,250		 5,000	
132	emergeons a l'éducation a la citoyenneté TLE mondoux	Nkozoa	3	LIVRES	UNITE	 3,315		 3,900	
133	Emergeons a l'éducation a la citoyenneté 2nde mondoux	Nkozoa	3	LIVRES	UNITE	 2,550		 3,000	
134	excellence en mathematique 1ere C et E victor techniko 	Nkozoa	3	LIVRES	UNITE	 5,525		 6,500	
135	emergeons en mathematique TLE Det TI mondoux 	Nkozoa	3	LIVRES	UNITE	 4,675		 5,500	
136	emergeons en mathematique TLE C E et F mondoux 	Nkozoa	3	LIVRES	UNITE	 5,100		 6,000	
137	Majors en mathematique TLE A CHARLES ASVA	Nkozoa	3	LIVRES	UNITE	 3,315		 3,900	
138	majors en mathematique 1ere A et SES CHARLES ASVA	Nkozoa	3	LIVRES	UNITE	 2,975		 3,500	
139	Emergence en phylosophie TLE scientifiques	Nkozoa	3	LIVRES	UNITE	 4,250		 5,000	
140	geographie 6eme et 1ere annee 40	Nkozoa	5	LIVRES	UNITE	 3,570		 4,200	
141	Planete cameroun 5eme et 2eme annee victor E b edicef	Nkozoa	5	LIVRES	UNITE	 3,655		 4,300	
142	planete cameroun 3eme victor botnem	Nkozoa	5	LIVRES	UNITE	 3,655		 4,300	
143	planete cameroun 4eme victor E b edicef	Nkozoa	5	LIVRES	UNITE	 3,655		 4,300	
144	Planete cameroun histoire 3eme EDICEF 40	Nkozoa	5	LIVRES	UNITE	 3,145		 3,700	
145	Planete cameroun histoire '3eme EDICEF 40	Nkozoa	5	LIVRES	UNITE	 3,145		 3,700	
146	SVT 3eme	Nkozoa	5	LIVRES	UNITE	 4,250		 5,000	
147	Mathematique 3eme	Nkozoa	5	LIVRES	UNITE	 3,825		 4,500	
148	education a la citoyennete 6eme et 5eme mondoux	Nkozoa	5	LIVRES	UNITE	 2,975		 3,500	
149	education a la citoyennete 4eme et 3eme mondoux	Nkozoa	5	LIVRES	UNITE	 2,975		 3,500	
150	INFO 3eme	Nkozoa	5	LIVRES	UNITE	 3,570		 4,200	
151	physique chimie technologie 4eme leveil nmi	Nkozoa	4	LIVRES	UNITE	 2,975		 3,500	
152	les conquerant en informatique 6eme modeste N masseu	Nkozoa	5	LIVRES	UNITE	 1,530		 1,800	
153	technologie de l'information et de la communication CMI,CM2	Nkozoa	5	LIVRES	UNITE	 1,530		 1,800	
154	SCIENCE CMI cosmos	Nkozoa	5	LIVRES	UNITE	 1,360		 1,600	
155	sciences et technogie CE2  cosmoss	Nkozoa	5	LIVRES	UNITE	 1,360		 1,600	
156	informatique CE2	Nkozoa	5	LIVRES	UNITE	 1,360		 1,600	
157	Majors en science et technologie CE1 cosmos ASVA	Nkozoa	5	LIVRES	UNITE	 3,825		 4,500	
158	LEVEIL EN ANGLAIS 3eme NMI	Nkozoa	6	LIVRES	UNITE	 3,825		 4,500	
159	ERWARCHEN DEUTSCH ALLEMAND 4eme NMI	Nkozoa	5	LIVRES	UNITE	 3,400		 4,000	
160	ANGLAIS 4eme NMI	Nkozoa	1	LIVRES	UNITE	 3,825		 4,500	
161	ANGLAIS 5eme afric education	Nkozoa	5	LIVRES	UNITE	 3,825		 4,500	
162	Anglais 6eme afric education	Nkozoa	5	LIVRES	UNITE	 4,250		 5,000	
163	informatique 5eme	Nkozoa	5	LIVRES	UNITE	 3,400		 4,000	
164	informatique 4eme	Nkozoa	5	LIVRES	UNITE	 3,400		 4,000	
165	SYLLABAIRE CE1 CE2	Nkozoa	5	LIVRES	UNITE	 1,275		 1,500	
166	Majors en sience et technologie SIL	Nkozoa	5	LIVRES	UNITE	 1,275		 1,500	
167	Majors en science et tecnologie CP	Nkozoa	5	LIVRES	UNITE	 1,275		 1,500	
168	les brillant en TIC SIL ET CP	Nkozoa	5	LIVRES	UNITE	 1,275		 1,500	
169	inclusive englihs for francophonne primary school SIL	Nkozoa	4	LIVRES	UNITE	 1,530		 1,800	
170	Anglais CM2 new curriculum pupupils book cosmos	Nkozoa	5	LIVRES	UNITE	 1,615		 1,900	
171	anglais CE1 pupils book new curriculum cosmos	Nkozoa	5	LIVRES	UNITE	 1,530		 1,800	
172	Syllabaire SIL CP afric educ	Nkozoa	5	LIVRES	UNITE	 1,275		 1,500	
173	Science humaine et sociale CM2	Nkozoa	5	LIVRES	UNITE	 1,573		 1,850	
174	Vivons ensemble SIL CP nathan	Nkozoa	5	LIVRES	UNITE	 1,275		 1,500	
175	anglais CM1 PBASVA	Nkozoa	5	LIVRES	UNITE	 1,615		 1,900	
176	LES BRILLANT EN ANGLAIS CE2 EDUCATION	Nkozoa	5	LIVRES	UNITE	 1,530		 1,800	
177	champions en francais CM1	Nkozoa	5	LIVRES	UNITE	 1,615		 1,900	
178	MATHematique CM1 NATHAN	Nkozoa	5	LIVRES	UNITE	 1,615		 1,900	
179	Champion en francais CE2 nathan	Nkozoa	5	LIVRES	UNITE	 1,530		 1,800	
180	mathematique CE2	Nkozoa	5	LIVRES	UNITE	 1,530		 1,800	
181	Français CE1 MANUEL de l'éleve nathan	Nkozoa	5	LIVRES	UNITE	 1,530		 1,800	
182	champion en francais CP edicef 30	Nkozoa	5	LIVRES	UNITE	 1,275		 1,500	
183	Champion en francais SIL edicef 	Nkozoa	5	LIVRES	UNITE	 1,275		 1,500	
184	A VOS MATHS CP nathan	Nkozoa	5	LIVRES	UNITE	 1,275		 1,500	
185	A VOS MATHS SIL nathan	Nkozoa	5	LIVRES	UNITE	 1,275		 1,500	
186	Emergeons en education civique et morale 1ere MONDOUX	Nkozoa	3	LIVRES	UNITE	 2,550		 3,000	
187	EMERGEONS EN PHYLOSOPHIE 1ere MONDOUX	Nkozoa	2	LIVRES	UNITE	 3,825		 4,500	
188	Emergeons en phYLOSOPHIE 1ere C D ET TI MONDOUX	Nkozoa	3	LIVRES	UNITE	 2,550		 3,000	
189	Phylosophie TLE littérature	Nkozoa	2	LIVRES	UNITE	 6,630		 7,800	
190	LATINA LINGUA 4e et 3 eme	Nkozoa	3	LIVRES	UNITE	 4,250		 5,000	
191	L'excellence en mathematique 2nde A V techiko Nnmi	Nkozoa	3	LIVRES	UNITE	 3,825		 4,500	
192	nueva didacica del espanol 5	Nkozoa	3	LIVRES	UNITE	 5,100		 6,000	
193	J' aime le chinois tome 1 AFRIC EDUCATION	Nkozoa	3	LIVRES	UNITE	 4,675		 5,500	
194	J' aime le chinois tome 2 AFRIC EDUCATION	Nkozoa	3	LIVRES	UNITE	 3,400		 4,000	
195	Nueva didacica del espanol 3	Nkozoa	3	LIVRES	UNITE	 4,250		 5,000	
196	SVTEEHB 4eme mondoux	Nkozoa	5	LIVRES	UNITE	 3,400		 4,000	
197	SVT 6eme afric education	Nkozoa	4	LIVRES	UNITE	 4,250		 5,000	
198	mathematiques 4eme belles lettre	Nkozoa	5	LIVRES	UNITE	 3,825		 4,500	
199	mathematiques 3eme	Nkozoa	5	LIVRES	UNITE	 3,825		 4,500	
200	Français 3eme AFRIC education	Nkozoa	5	LIVRES	UNITE	 4,250		 5,000	
201	francais 4eme afri education	Nkozoa	5	LIVRES	UNITE	 4,250		 5,000	
202	francais 5eme afri education	Nkozoa	5	LIVRES	UNITE	 3,825		 4,500	
203	mathematique 6eme MONDOUX	Nkozoa	5	LIVRES	UNITE	 3,400		 4,000	
204	francais 6eme nathan	Nkozoa	5	LIVRES	UNITE	 3,570		 4,200	
205	littérature CE1 comment cava benjamin afric education	Nkozoa	5	LIVRES	UNITE	 850		 1,000	
206	littérature CM1 la naissan ce d'une grande nation 	Nkozoa	5	LIVRES	UNITE	 850		 1,000	
207	littérature CM2 une nation benie	Nkozoa	5	LIVRES	UNITE	 850		 1,000	
208	livre d'allemand 3EM	Nkozoa	5	LIVRES	UNITE	 3,825		4500	
209	Chemise cartonnées de 70	Nkozoa	20	CHEMISE	UNITE				
210	SOUS Chemise  de 160	Nkozoa	20	SOUS CHEMISE	UNITE				
211	Sous Chemise de 200	Nkozoa	20	SOUS CHEMISE	UNITE				
212	Encre noir	Nkozoa	2	IMPRIMERIE	UNITE				
213	Encre bleu	Nkozoa	4	IMPRIMERIE	UNITE				
214	sac de classe MATERNELLE	Nkozoa	3	sacs scolaire	UNITE				
215	sac de classe MOYENNE SECTION ET SIL	Nkozoa	5	sacs scolaire	UNITE				
216	sac de classe SIL ET CP	Nkozoa	3	sacs scolaire	UNITE				
217	sac de classe SPIDERMAN	Nkozoa	3	sacs scolaire	UNITE				
218	sac PRIMAIRE AVEC NOURNOUS 	Nkozoa	5	sacs scolaire	UNITE				
219	SAC PRIMAIRE SIMPLE	Nkozoa	5	sacs scolaire	UNITE				
220	SAC NOIR COLLEGE	Nkozoa	3	sacs scolaire	UNITE				
221	SAC NOIR BLEU COOLLEGE	Nkozoa	2	sacs scolaire	UNITE
DATA;

$lines = explode("\n", trim($data));
$parsed = [];
$categories = [];

foreach ($lines as $line) {
    $parts = explode("\t", $line);
    if (count($parts) >= 9) {
        $num = trim($parts[0]);
        $designation = trim($parts[1]);
        $depot = trim($parts[2]);
        $qty = trim($parts[3]);
        $famille = trim($parts[4]);
        $cond = trim($parts[5]);
        $p_achat = str_replace([' ', ','], '', trim($parts[6]));
        $marge = trim($parts[7]);
        $p_vente = str_replace([' ', ','], '', trim($parts[8]));

        $p_achat = floatval($p_achat ?: 0);
        $p_vente = floatval($p_vente ?: 0);
        $qty = intval($qty ?: 0);

        if ($famille) {
            $categories[$famille] = true;
        }

        $parsed[] = [
            'num' => $num,
            'name' => $designation,
            'depot' => $depot,
            'qty' => $qty,
            'category' => $famille,
            'unit' => $cond,
            'price_purchase' => $p_achat,
            'price_sale' => $p_vente
        ];
    }
}

$cat_list = array_keys($categories);
sort($cat_list);
$cat_map = [];
foreach ($cat_list as $index => $cat) {
    $cat_map[$cat] = $index + 1;
}

echo "<!DOCTYPE html>
<html>
<head>
    <title>Importation Mandel</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    <style>
        body { background: #f3f4f6; font-family: sans-serif; padding-top: 50px; }
        .card { border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
    </style>
</head>
<body>
<div class='container'>
    <div class='row justify-content-center'>
        <div class='col-md-8'>
            <div class='card p-5 bg-white'>
                <h2 class='text-center mb-4 text-primary'>Importation des Données Mandel</h2>";

try {
    // Nettoyage automatique des anciens fichiers obsolètes à la racine
    $obsolete_files = [
        '../database.sql',
        '../parse_products.php',
        '../parse_products.py',
        '../categories.php',
        '../products.php',
        '../product-add.php',
        '../product-edit.php',
        '../product-delete.php',
        '../stock-transfers.php',
        '../orders.php',
        '../order-create.php',
        '../invoice.php',
        '../contacts.php',
        '../shops.php',
        '../config/db.php',
        '../includes/header.php',
        '../includes/footer.php',
        '../assets/css/style.css'
    ];
    foreach ($obsolete_files as $file_rel) {
        $file_path = __DIR__ . '/' . $file_rel;
        if (file_exists($file_path)) {
            @unlink($file_path);
        }
    }
    // Nettoyage des dossiers obsolètes vides
    $obsolete_dirs = [
        '../config',
        '../includes',
        '../assets/css',
        '../assets'
    ];
    foreach ($obsolete_dirs as $dir_rel) {
        $dir_path = __DIR__ . '/' . $dir_rel;
        if (is_dir($dir_path)) {
            @rmdir($dir_path);
        }
    }

    $pdo->beginTransaction();

    // 1. Vider les tables
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $pdo->exec("TRUNCATE TABLE product_stock;");
    $pdo->exec("TRUNCATE TABLE products;");
    $pdo->exec("TRUNCATE TABLE categories;");
    $pdo->exec("TRUNCATE TABLE shops;");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    // 2. Insérer les catégories
    $stmtCat = $pdo->prepare("INSERT INTO categories (id, name, description) VALUES (?, ?, ?)");
    foreach ($cat_list as $cat) {
        $id = $cat_map[$cat];
        $stmtCat->execute([$id, $cat, "Produits de la famille " . $cat]);
    }
    echo "<div class='alert alert-success'>&check; " . count($cat_list) . " catégories importées.</div>";

    // 3. Insérer les boutiques
    $stmtShop = $pdo->prepare("INSERT INTO shops (id, name, address) VALUES (?, ?, ?)");
    $stmtShop->execute([1, 'Nkozoa', 'Dépôt Principal Nkozoa']);
    $stmtShop->execute([2, 'Boutique B', 'Dépôt Secondaire']);
    echo "<div class='alert alert-success'>&check; 2 boutiques créées (Nkozoa & Boutique B).</div>";

    // 4. Insérer les produits
    $stmtProd = $pdo->prepare("INSERT INTO products (id, name, sku, category_id, unit, price_purchase, price_sale, min_stock_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($parsed as $p) {
        $sku = sprintf("ART-%04d", intval($p['num']));
        $stmtProd->execute([
            $p['num'],
            $p['name'],
            $sku,
            $cat_map[$p['category']],
            $p['unit'],
            $p['price_purchase'],
            $p['price_sale'],
            5
        ]);
    }
    echo "<div class='alert alert-success'>&check; " . count($parsed) . " produits insérés au catalogue.</div>";

    // 5. Insérer les stocks initiaux
    $stmtStock = $pdo->prepare("INSERT INTO product_stock (product_id, shop_id, quantity) VALUES (?, ?, ?)");
    $stockCount = 0;
    foreach ($parsed as $p) {
        if ($p['qty'] > 0) {
            $stmtStock->execute([$p['num'], 1, $p['qty']]);
            $stockCount++;
        }
    }
    echo "<div class='alert alert-success'>&check; " . $stockCount . " lignes de stock initialisées pour le dépôt de Nkozoa.</div>";

    $pdo->commit();
    echo "<hr>
          <div class='alert alert-info text-center fw-bold mb-4'>Importation terminée avec succès !</div>
          <div class='text-center'>
              <a href='../frontend/index.php' class='btn btn-success px-4 py-2 fw-bold'>Accéder au Tableau de Bord &rarr;</a>
          </div>";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "<div class='alert alert-danger'>Erreur lors de l'importation : " . $e->getMessage() . "</div>";
}

echo "          </div>
        </div>
    </div>
</div>
</body>
</html>";
