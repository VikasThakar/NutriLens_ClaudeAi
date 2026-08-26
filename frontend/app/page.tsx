import { Features } from "@/components/marketing/features";
import { FinalCta } from "@/components/marketing/final-cta";
import { Hero } from "@/components/marketing/hero";
import { HowItWorks } from "@/components/marketing/how-it-works";
import { MarketingFooter } from "@/components/marketing/marketing-footer";
import { MarketingNav } from "@/components/marketing/marketing-nav";

export default function LandingPage() {
  return (
    <>
      <MarketingNav />
      <main id="main" className="flex-1">
        <Hero />
        <HowItWorks />
        <Features />
        <FinalCta />
      </main>
      <MarketingFooter />
    </>
  );
}
